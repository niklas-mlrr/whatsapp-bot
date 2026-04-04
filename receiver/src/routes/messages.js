/**
 * Message sending routes
 */

import express from 'express';
import axios from 'axios';
import fs from 'fs';
import { verifyApiKey } from '../middleware/auth.js';
import { waitForSocketReady, getSocketInstance, isSocketConnected } from '../middleware/socketReady.js';
import { resolveChatJid } from '../utils/jid.js';
import { loadMediaBuffer } from '../handlers/media.js';
import { storeSentMessage } from '../whatsappClient.js';

const router = express.Router();

/**
 * @param {import('express').Application} app - Express app instance
 * @param {Map} pollMessagesStore - Poll messages store
 */
export function createMessageRoutes(app, pollMessagesStore) {
    app.post('/send-message', verifyApiKey, async (req, res) => {
        console.log('Received send-message request:', {
            chat: req.body.chat,
            type: req.body.type,
            contentLength: req.body.content?.length,
            mediaType: req.body.media ? 'present' : 'missing',
            mimetype: req.body.mimetype,
            filename: req.body.filename,
            quoted_message_whatsapp_id: req.body.quoted_message_whatsapp_id
        });

        const sockInstance = getSocketInstance();
        if (!sockInstance) {
            const error = 'WhatsApp socket not initialized';
            console.error(error);
            return res.status(500).json({ error });
        }

        if (!isSocketConnected()) {
            const error = 'WhatsApp socket not connected';
            console.error(error);
            return res.status(500).json({ error });
        }

        try {
            await waitForSocketReady(20000);
        } catch (syncError) {
            console.error('WhatsApp socket not ready:', syncError.message);
            return res.status(503).json({ error: 'WhatsApp initial sync incomplete', details: syncError.message });
        }

        const { chat, type, content, media, mimetype, filename, quoted_message_whatsapp_id, quoted_message_content, quoted_message_from_me } = req.body;
        const targetChat = resolveChatJid(chat);

        // Validate required fields
        if (!targetChat || !type) {
            const error = 'Missing required fields: chat and type are required';
            console.error(error);
            return res.status(400).json({ error });
        }

        try {
            // Build quoted message if replying (using data from payload to avoid deadlock)
            let quotedMessage = null;
            if (quoted_message_whatsapp_id) {
                console.log('Building quoted message from payload:', {
                    whatsapp_message_id: quoted_message_whatsapp_id,
                    content: quoted_message_content,
                    from_me: quoted_message_from_me
                });

                quotedMessage = {
                    key: {
                        remoteJid: targetChat,
                        id: quoted_message_whatsapp_id,
                        fromMe: quoted_message_from_me === true
                    },
                    message: {
                        conversation: quoted_message_content || ''
                    }
                };
            }

            let sentMessage;
            if (type === 'text') {
                sentMessage = await sendTextMessage(sockInstance, targetChat, content, quotedMessage);
            } else if (type === 'image' && media) {
                sentMessage = await sendImageMessage(sockInstance, targetChat, media, mimetype, content, quotedMessage);
            } else if (type === 'document' && media) {
                sentMessage = await sendDocumentMessage(sockInstance, targetChat, media, mimetype, filename, content, quotedMessage);
            } else if (type === 'video' && media) {
                sentMessage = await sendVideoMessage(sockInstance, targetChat, media, mimetype, content, quotedMessage);
            } else if (type === 'audio' && media) {
                sentMessage = await sendAudioMessage(sockInstance, targetChat, media, mimetype, quotedMessage);
            } else if (type === 'poll') {
                sentMessage = await sendPollMessage(sockInstance, targetChat, req.body.pollData, quotedMessage, pollMessagesStore);
            } else {
                const error = `Unsupported message type '${type}' or missing media`;
                console.error(error);
                return res.status(400).json({ error });
            }

            console.log('Message sent successfully to', chat);
            res.json({
                status: 'sent',
                messageId: sentMessage?.key?.id || null
            });

        } catch (err) {
            console.error('Failed to send message:', {
                error: err.message,
                stack: err.stack,
                chat,
                type,
                hasMedia: !!media,
                mediaType: media?.substring(0, 20) + (media?.length > 20 ? '...' : '')
            });
            res.status(500).json({
                error: 'Failed to send message',
                details: err.message,
                type: err.name
            });
        }
    });
}

/**
 * Send a text message
 */
async function sendTextMessage(sock, chatJid, content, quotedMessage) {
    console.log('Sending text message to', chatJid, quotedMessage ? 'with quote' : '');
    const messageOptions = quotedMessage ? { quoted: quotedMessage } : {};
    const contentObj = { text: content || '' };
    const sentMessage = await sock.sendMessage(chatJid, contentObj, { ...messageOptions, waitForAck: false });

    // Store for retry support
    try {
        const id = sentMessage?.key?.id;
        if (id) {
            const protoLike = sentMessage?.message || { conversation: content || '' };
            storeSentMessage(id, protoLike);
        }
    } catch (_) {}

    return sentMessage;
}

/**
 * Send an image message
 */
async function sendImageMessage(sock, chatJid, media, mimetype, content, quotedMessage) {
    console.log('Processing image message for', chatJid);
    try {
        let buffer, actualMimetype;

        if (media.startsWith('http')) {
            console.log('Downloading image from URL:', media);
            const response = await axios({
                method: 'GET',
                url: media,
                responseType: 'arraybuffer',
                timeout: 30000,
                validateStatus: status => status < 500
            });

            if (response.status !== 200) {
                throw new Error(`Failed to download image: ${response.status} ${response.statusText}`);
            }

            buffer = Buffer.from(response.data);
            actualMimetype = mimetype || response.headers['content-type'] || 'image/jpeg';
        } else if (fs.existsSync(media)) {
            console.log('Reading local file:', media);
            buffer = fs.readFileSync(media);
            actualMimetype = mimetype || 'image/jpeg';
        } else if (media.startsWith('data:')) {
            console.log('Processing base64 image data');
            const matches = media.match(/^data:([A-Za-z-+/]+);base64,(.+)$/);
            if (!matches || matches.length !== 3) {
                throw new Error('Invalid base64 image data');
            }
            buffer = Buffer.from(matches[2], 'base64');
            actualMimetype = mimetype || matches[1];
        } else {
            throw new Error('Unsupported media format. Must be a URL or data URI');
        }

        console.log('Sending image to WhatsApp', { size: buffer.length, mimetype: actualMimetype });
        const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { waitForAck: false };
        const sentMessage = await sock.sendMessage(chatJid, {
            image: buffer,
            mimetype: actualMimetype,
            caption: content || ''
        }, messageOptions);

        // Store for retry support
        try {
            const id = sentMessage?.key?.id;
            if (id && sentMessage?.message) {
                storeSentMessage(id, sentMessage.message);
            }
        } catch (_) {}

        return sentMessage;
    } catch (error) {
        console.error('Error processing image:', {
            error: error.message,
            stack: error.stack,
            mediaType: typeof media,
            mediaLength: media?.length,
            mediaStart: media?.substring(0, 100)
        });
        throw new Error(`Failed to process image: ${error.message}`);
    }
}

/**
 * Send a document message
 */
async function sendDocumentMessage(sock, chatJid, media, mimetype, filename, content, quotedMessage) {
    console.log('Processing document message for', chatJid);
    try {
        const { buffer, mimetype: actualMimetype } = await loadMediaBuffer(media, mimetype, 'application/octet-stream');

        // Determine file extension from mimetype or filename
        let fileExtension = '';
        if (filename) {
            const extMatch = filename.match(/\.([^.]+)$/);
            if (extMatch) {
                fileExtension = extMatch[0];
            }
        }
        if (!fileExtension && actualMimetype && actualMimetype.includes('/')) {
            fileExtension = '.' + actualMimetype.split('/')[1].split('+')[0];
        }

        const resolvedFilename = filename || `document${fileExtension}`;

        console.log('Sending document to WhatsApp:', {
            filename: resolvedFilename,
            mimetype: actualMimetype,
            size: buffer.length
        });

        const documentMessage = {
            document: buffer,
            mimetype: actualMimetype,
            fileName: resolvedFilename
        };

        if (content && content.trim().length > 0) {
            documentMessage.caption = content;
        }

        const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { waitForAck: false };
        const sentMessage = await sock.sendMessage(chatJid, documentMessage, messageOptions);

        // Store for retry support
        try {
            const id = sentMessage?.key?.id;
            if (id && sentMessage?.message) {
                storeSentMessage(id, sentMessage.message);
            }
        } catch (_) {}

        return sentMessage;
    } catch (error) {
        console.error('Error processing document:', {
            error: error.message,
            stack: error.stack,
            mediaType: typeof media,
            mediaLength: media?.length,
            mediaStart: media?.substring(0, 100)
        });
        throw new Error(`Failed to process document: ${error.message}`);
    }
}

/**
 * Send a video message
 */
async function sendVideoMessage(sock, chatJid, media, mimetype, content, quotedMessage) {
    console.log('Processing video message for', chatJid);
    try {
        const { buffer, mimetype: actualMimetype } = await loadMediaBuffer(media, mimetype, 'video/mp4');

        console.log('Sending video to WhatsApp:', {
            mimetype: actualMimetype,
            size: buffer.length
        });

        const videoMessage = {
            video: buffer,
            mimetype: actualMimetype
        };

        if (content && content.trim().length > 0) {
            videoMessage.caption = content;
        }

        const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { waitForAck: false };
        const sentMessage = await sock.sendMessage(chatJid, videoMessage, messageOptions);

        // Store for retry support
        try {
            const id = sentMessage?.key?.id;
            if (id && sentMessage?.message) {
                storeSentMessage(id, sentMessage.message);
            }
        } catch (_) {}

        return sentMessage;
    } catch (error) {
        console.error('Error processing video:', {
            error: error.message,
            stack: error.stack,
            mediaType: typeof media,
            mediaLength: media?.length,
            mediaStart: media?.substring(0, 100)
        });
        throw new Error(`Failed to process video: ${error.message}`);
    }
}

/**
 * Send an audio message
 */
async function sendAudioMessage(sock, chatJid, media, mimetype, quotedMessage) {
    console.log('Processing audio message for', chatJid);
    try {
        const { buffer, mimetype: actualMimetype } = await loadMediaBuffer(media, mimetype, 'audio/ogg; codecs=opus');

        console.log('Sending audio to WhatsApp:', {
            mimetype: actualMimetype,
            size: buffer.length
        });

        const audioMessage = {
            audio: buffer,
            mimetype: actualMimetype
        };

        const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { waitForAck: false };
        const sentMessage = await sock.sendMessage(chatJid, audioMessage, messageOptions);

        // Store for retry support
        try {
            const id = sentMessage?.key?.id;
            if (id && sentMessage?.message) {
                storeSentMessage(id, sentMessage.message);
            }
        } catch (_) {}

        return sentMessage;
    } catch (error) {
        console.error('Error processing audio:', {
            error: error.message,
            stack: error.stack,
            mediaType: typeof media,
            mediaLength: media?.length,
            mediaStart: media?.substring(0, 100)
        });
        throw new Error(`Failed to process audio: ${error.message}`);
    }
}

/**
 * Send a poll message
 */
async function sendPollMessage(sock, chatJid, pollData, quotedMessage, pollMessagesStore) {
    console.log('Processing poll message for', chatJid);
    try {
        if (!pollData || !pollData.name || !pollData.options || pollData.options.length === 0) {
            throw new Error('Poll data is required with name and options');
        }

        // Validate poll options
        if (pollData.options.length < 2) {
            throw new Error('Poll must have at least 2 options');
        }

        if (pollData.options.length > 12) {
            throw new Error('Poll can have at most 12 options');
        }

        console.log('Sending poll to WhatsApp:', {
            name: pollData.name,
            optionsCount: pollData.options.length,
            pollType: pollData.pollType || 'POLL',
            contentType: pollData.pollContentType || 'TEXT'
        });

        const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { waitForAck: false };

        // Map selectableOptionsCount (incoming/backend) -> selectableCount (Baileys)
        const selectableCount = (typeof pollData.selectableOptionsCount === 'number')
            ? pollData.selectableOptionsCount
            : 0;

        const sentMessage = await sock.sendMessage(chatJid, {
            poll: {
                name: pollData.name,
                selectableCount: selectableCount,
                values: pollData.options.map(option => option.optionName || option.name || option)
            }
        }, messageOptions);

        // Store for retry support
        try {
            const id = sentMessage?.key?.id;
            if (id && sentMessage?.message) {
                storeSentMessage(id, sentMessage.message);
            }
        } catch (_) {}

        // Store the sent poll message for vote aggregation
        if (sentMessage?.key?.id) {
            pollMessagesStore.set(sentMessage.key.id, sentMessage);
            console.log('Stored sent poll message for vote aggregation:', sentMessage.key.id);
        }

        return sentMessage;
    } catch (error) {
        console.error('Error processing poll:', {
            error: error.message,
            stack: error.stack,
            pollData: pollData
        });
        throw new Error(`Failed to process poll: ${error.message}`);
    }
}

export default { createMessageRoutes };
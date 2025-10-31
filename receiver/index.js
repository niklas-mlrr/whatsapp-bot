import { connectToWhatsApp, setReconnectCallback, storeSentMessage } from './src/whatsappClient.js';
import express from 'express';
import bodyParser from 'body-parser';
import axios from 'axios';
import fs from 'fs';
import { promisify } from 'util';
import stream from 'stream';
import path from 'path';
import * as whatsappClient from './src/whatsappClient.js';

const pipeline = promisify(stream.pipeline);

let sockInstance = null;
let isConnected = false;
let awaitingInitialSync = true;

// Function to set the socket instance
function setSocketInstance(sock) {
    sockInstance = sock;
    
    // Check if socket is already connected
    // The socket is considered connected if it has a user object
    if (sock && sock.user) {
        isConnected = true;
        awaitingInitialSync = false;
        console.log('Socket already connected and ready.');
    }
    
    // Listen for connection updates
    if (sock.ev && sock.ev.on) {
        sock.ev.on('connection.update', (update) => {
            console.log('Connection update received:', update);
            if (update.connection === 'open') {
                isConnected = true;
                awaitingInitialSync = !(update?.receivedPendingNotifications ?? update?.isOnline ?? true);
                console.log('Socket connected and ready.', { awaitingInitialSync });
            } else if (update?.receivedPendingNotifications) {
                awaitingInitialSync = false;
                console.log('Initial sync complete.');
            } else if (update.connection === 'close') {
                isConnected = false;
                awaitingInitialSync = true;
                console.log('Socket connection closed.');
                // Reconnection is handled in whatsappClient.js
            }
        });
    }
}

async function waitForSocketReady(timeoutMs = 10000) {
    if (!sockInstance) {
        throw new Error('WhatsApp socket not initialized');
    }

    if (!awaitingInitialSync) {
        return;
    }

    await Promise.race([
        new Promise((resolve) => {
            if (!sockInstance?.ev?.on) {
                resolve();
                return;
            }

            const handler = (update) => {
                if (update?.receivedPendingNotifications || update?.isOnline) {
                    awaitingInitialSync = false;
                    sockInstance.ev.off?.('connection.update', handler);
                    resolve();
                }
            };

            sockInstance.ev.on('connection.update', handler);

            if (!awaitingInitialSync) {
                sockInstance.ev.off?.('connection.update', handler);
                resolve();
            }
        }),
        new Promise((_, reject) => setTimeout(() => reject(new Error('WhatsApp initial sync timeout')), timeoutMs)),
    ]);
}

async function start() {
    // Set up the reconnect callback before initial connection
    setReconnectCallback((newSock) => {
        setSocketInstance(newSock);
    });
    
    const sock = await connectToWhatsApp();
    setSocketInstance(sock);

    const app = express();
    app.use(bodyParser.json({ limit: '10mb' }));

    // Health check endpoint
    app.get('/status', (req, res) => {
        res.json({
            status: 'running',
            whatsapp: {
                initialized: !!sockInstance,
                connected: isConnected,
                user: sockInstance?.user ? {
                    id: sockInstance.user.id,
                    name: sockInstance.user.name
                } : null
            }
        });
    });

    async function loadMediaBuffer(media, mimetype, defaultMime = 'application/octet-stream') {
        if (!media) {
            throw new Error('Media payload missing');
        }

        if (media.startsWith('http')) {
            console.log('Downloading media from URL:', media);
            const response = await axios({
                method: 'GET',
                url: media,
                responseType: 'arraybuffer',
                timeout: 30000,
                validateStatus: status => status < 500
            });

            if (response.status !== 200) {
                throw new Error(`Failed to download media: ${response.status} ${response.statusText}`);
            }

            return {
                buffer: Buffer.from(response.data),
                mimetype: mimetype || response.headers['content-type'] || defaultMime
            };
        }

        if (fs.existsSync(media)) {
            console.log('Reading local media file:', media);
            const fileData = fs.readFileSync(media);
            return {
                buffer: Buffer.from(fileData),
                mimetype: mimetype || defaultMime
            };
        }

        if (media.startsWith('data:')) {
            const matches = media.match(/^data:([^;]+);base64,(.+)$/);
            if (!matches || matches.length !== 3) {
                throw new Error('Invalid base64 media data');
            }

            return {
                buffer: Buffer.from(matches[2], 'base64'),
                mimetype: mimetype || matches[1] || defaultMime
            };
        }

        throw new Error('Unsupported media format. Must be a URL, local file path, or data URI');
    }

    // Middleware to verify API key for send-message endpoint
    const verifyApiKey = (req, res, next) => {
        const apiKey = process.env.RECEIVER_API_KEY;
        
        if (!apiKey) {
            console.warn('SECURITY WARNING: RECEIVER_API_KEY not set in environment');
            // In production, this should block the request
            if (process.env.NODE_ENV === 'production') {
                return res.status(503).json({ error: 'Service unavailable: API key not configured' });
            }
        }
        
        const providedKey = req.headers['x-api-key'] || req.headers['authorization'];
        
        // Remove 'Bearer ' prefix if present
        let cleanKey = providedKey;
        if (cleanKey && cleanKey.startsWith('Bearer ')) {
            cleanKey = cleanKey.substring(7);
        }
        
        // Verify API key using constant-time comparison
        if (apiKey && cleanKey !== apiKey) {
            console.warn('Unauthorized access attempt to /send-message', {
                ip: req.ip,
                userAgent: req.headers['user-agent']
            });
            return res.status(401).json({ error: 'Unauthorized: Invalid API key' });
        }
        
        next();
    };

    const resolveChatJid = (value = '') => {
        if (!value) {
            return value;
        }
        if (value.endsWith('@g.us') || value.endsWith('@s.whatsapp.net')) {
            return value;
        }
        if (value.includes('@')) {
            return `${value.split('@')[0]}@s.whatsapp.net`;
        }
        return `${value}@s.whatsapp.net`;
    };

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

        if (!sockInstance) {
            const error = 'WhatsApp socket not initialized';
            console.error(error);
            return res.status(500).json({ error });
        }

        if (!isConnected) {
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
                
                // Build the quoted message key
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
                console.log('Sending text message to', chat, quotedMessage ? 'with quote' : '');
                const messageOptions = quotedMessage ? { quoted: quotedMessage } : {};
                const contentObj = { text: content || '' };
                sentMessage = await sockInstance.sendMessage(targetChat, contentObj, { ...messageOptions, waitForAck: false });
                // Store a proto-like representation for retry support
                try {
                    const id = sentMessage?.key?.id;
                    if (id) {
                        const protoLike = { conversation: content || '' };
                        storeSentMessage(id, protoLike);
                    }
                } catch (_) {}
            } else if (type === 'image' && media) {
                console.log('Processing image message for', chat);
                try {
                    // Check if media is a URL, local file, or base64 data
                    if (media.startsWith('http')) {
                        console.log('Downloading image from URL:', media);
                        // Download the image from the URL
                        const response = await axios({
                            method: 'GET',
                            url: media,
                            responseType: 'arraybuffer',
                            timeout: 30000, // 30 seconds timeout
                            validateStatus: status => status < 500 // Don't throw for 4xx errors
                        });

                        if (response.status !== 200) {
                            throw new Error(`Failed to download image: ${response.status} ${response.statusText}`);
                        }
                        
                        // Get the actual MIME type from response headers if not provided
                        const actualMimetype = mimetype || 
                                            response.headers['content-type'] || 
                                            'image/jpeg';
                        
                        console.log('Sending image to WhatsApp');
                        // Send the image to WhatsApp
                        const messageOptions = quotedMessage ? { quoted: quotedMessage, upload: true, waitForAck: false } : { quoted: null, upload: true, waitForAck: false };
                        sentMessage = await sockInstance.sendMessage(
                            targetChat, 
                            { 
                                image: response.data, 
                                mimetype: actualMimetype,
                                caption: content || '' 
                            },
                            messageOptions
                        );
                    } else if (fs.existsSync(media)) {
                        console.log('Reading local file:', media);
                        // Read the local file
                        const fileData = fs.readFileSync(media);
                        const actualMimetype = mimetype || 'image/jpeg';
                        
                        console.log('Sending local file to WhatsApp');
                        const messageOptions = quotedMessage ? { quoted: quotedMessage, upload: true, waitForAck: false } : { quoted: null, upload: true, waitForAck: false };
                        sentMessage = await sockInstance.sendMessage(
                            targetChat,
                            {
                                image: fileData,
                                mimetype: actualMimetype,
                                caption: content || ''
                            },
                            messageOptions
                        );
                    } else if (media.startsWith('data:')) {
                        console.log('Processing base64 image data');
                        // Handle base64 data URL
                        const matches = media.match(/^data:([A-Za-z-+\/]+);base64,(.+)$/);
                        if (!matches || matches.length !== 3) {
                            throw new Error('Invalid base64 image data');
                        }
                        
                        const buffer = Buffer.from(matches[2], 'base64');
                        const actualMimetype = mimetype || matches[1];
                        
                        console.log('Sending base64 image to WhatsApp', {
                            size: buffer.length,
                            mimetype: actualMimetype
                        });
                        
                        // Create message object with media
                        const message = {
                            image: buffer,
                            mimetype: actualMimetype,
                            caption: content || ''
                        };
                        
                        // Send the message with the correct options
                        const sendOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { quoted: null, waitForAck: false };
                        sentMessage = await sockInstance.sendMessage(targetChat, message, sendOptions);
                    } else {
                        throw new Error('Unsupported media format. Must be a URL or data URI');
                    }
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
            } else if (type === 'document' && media) {
                console.log('Processing document message for', chat);
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

                    const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { quoted: null, waitForAck: false };
                    sentMessage = await sockInstance.sendMessage(targetChat, documentMessage, messageOptions);
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
            } else if (type === 'video' && media) {
                console.log('Processing video message for', chat);
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

                    const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { quoted: null, waitForAck: false };
                    sentMessage = await sockInstance.sendMessage(targetChat, videoMessage, messageOptions);
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
            } else if (type === 'audio' && media) {
                console.log('Processing audio message for', chat);
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

                    const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { quoted: null, waitForAck: false };
                    sentMessage = await sockInstance.sendMessage(targetChat, audioMessage, messageOptions);
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
            } else if (type === 'poll') {
                console.log('Processing poll message for', chat);
                try {
                    const { pollData } = req.body;
                    
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

                    // Create poll message structure for Baileys
                    // Send poll using the pollCreationMessage format
                    const messageOptions = quotedMessage ? { quoted: quotedMessage, waitForAck: false } : { quoted: null, waitForAck: false };
                    
                    sentMessage = await sockInstance.sendMessage(targetChat, {
                        poll: {
                            name: pollData.name,
                            selectableOptionsCount: pollData.selectableOptionsCount || 0,
                            values: pollData.options.map(option => option.optionName || option.name || option)
                        }
                    }, messageOptions);
                } catch (error) {
                    console.error('Error processing poll:', {
                        error: error.message,
                        stack: error.stack,
                        pollData: req.body.pollData
                    });
                    throw new Error(`Failed to process poll: ${error.message}`);
                }
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

    // Send reaction endpoint
    app.post('/send-reaction', async (req, res) => {
        console.log('Received send-reaction request:', req.body);
        
        const { chat, messageId, emoji, fromMe } = req.body;
        
        if (!sockInstance || !isConnected) {
            console.error('WhatsApp not connected');
            return res.status(503).json({ error: 'WhatsApp not connected' });
        }
        
        if (!chat || !messageId) {
            console.error('Missing required fields:', { chat, messageId });
            return res.status(400).json({ error: 'Missing chat or messageId' });
        }
        
        try {
            // Normalize chat JID
            // - Keep group JIDs (@g.us) as-is
            // - Keep any JID that already contains an '@'
            // - Convert bare numbers to @s.whatsapp.net
            let chatJid;
            if (typeof chat === 'string' && chat.includes('@')) {
                chatJid = chat; // already a JID (could be @g.us or @s.whatsapp.net)
            } else {
                const number = String(chat || '').replace(/^\+/, '');
                chatJid = `${number}@s.whatsapp.net`;
            }

            // Optional participant for group reactions
            const { participant } = req.body || {};

            // Build reaction payload for Baileys
            const reactionMessage = {
                react: {
                    text: emoji || '', // Empty string removes the reaction
                    key: {
                        remoteJid: chatJid,
                        id: messageId,
                        fromMe: fromMe === true || fromMe === 'true' // Handle boolean or string
                    }
                }
            };

            // If this is a group chat and a participant was provided, include it
            if (typeof participant === 'string' && participant.includes('@') && chatJid.endsWith('@g.us')) {
                reactionMessage.react.key.participant = participant;
            }

            console.log('Sending reaction to WhatsApp:', reactionMessage);
            await sockInstance.sendMessage(chatJid, reactionMessage);
            
            console.log('Reaction sent successfully');
            res.json({ status: 'sent' });
            
        } catch (err) {
            console.error('Failed to send reaction:', {
                error: err.message,
                stack: err.stack,
                chat,
                messageId,
                emoji
            });
            res.status(500).json({ 
                error: 'Failed to send reaction', 
                details: err.message
            });
        }
    });

    // Delete message endpoint
    app.post('/delete-message', verifyApiKey, async (req, res) => {
        console.log('Received delete-message request:', req.body);
        
        const { messageId, chatJid, forEveryone } = req.body;
        
        if (!sockInstance || !isConnected) {
            console.error('WhatsApp not connected');
            return res.status(503).json({ error: 'WhatsApp not connected' });
        }
        
        if (!messageId || !chatJid) {
            console.error('Missing required fields:', { messageId, chatJid });
            return res.status(400).json({ error: 'Missing messageId or chatJid' });
        }
        
        try {
            // Ensure chat JID has proper WhatsApp format
            const formattedChatJid = resolveChatJid(chatJid);
            
            // Delete message using Baileys sendMessage with delete key
            const deletePayload = {
                delete: {
                    remoteJid: formattedChatJid,
                    fromMe: true,
                    id: messageId
                }
            };
            
            console.log('Deleting message on WhatsApp:', { formattedChatJid, deletePayload });
            const result = await sockInstance.sendMessage(formattedChatJid, deletePayload);
            
            // Track the new delete message ID to ignore its status updates
            if (result?.key?.id) {
                whatsappClient.addEditMessageId(result.key.id);
                console.log('Tracking delete message ID:', result.key.id);
            }
            
            console.log('Message deleted successfully');
            res.json({ status: 'deleted' });
            
        } catch (err) {
            console.error('Failed to delete message:', {
                error: err.message,
                stack: err.stack,
                messageId,
                chatJid
            });
            res.status(500).json({ 
                error: 'Failed to delete message', 
                details: err.message
            });
        }
    });

    // Edit message endpoint
    app.post('/edit-message', verifyApiKey, async (req, res) => {
        console.log('Received edit-message request:', req.body);
        
        const { messageId, chatJid, newContent } = req.body;
        
        if (!sockInstance || !isConnected) {
            console.error('WhatsApp not connected');
            return res.status(503).json({ error: 'WhatsApp not connected' });
        }
        
        if (!messageId || !chatJid || !newContent) {
            console.error('Missing required fields:', { messageId, chatJid, newContent });
            return res.status(400).json({ error: 'Missing messageId, chatJid, or newContent' });
        }
        
        try {
            // Ensure chat JID has proper WhatsApp format
            const formattedChatJid = resolveChatJid(chatJid);
            
            // Edit message using Baileys protocol
            // The edit field needs to be a message key object
            const editPayload = {
                text: newContent,
                edit: {
                    remoteJid: formattedChatJid,
                    fromMe: true,
                    id: messageId
                }
            };
            
            console.log('Editing message on WhatsApp:', { formattedChatJid, editPayload });
            const result = await sockInstance.sendMessage(formattedChatJid, editPayload);
            
            // Track the new edit message ID to ignore its status updates
            if (result?.key?.id) {
                whatsappClient.addEditMessageId(result.key.id);
                console.log('Tracking edit message ID:', result.key.id);
            }
            
            console.log('Message edited successfully');
            res.json({ status: 'edited' });
            
        } catch (err) {
            console.error('Failed to edit message:', {
                error: err.message,
                stack: err.stack,
                messageId,
                chatJid,
                newContent
            });
            res.status(500).json({ 
                error: 'Failed to edit message', 
                details: err.message
            });
        }
    });

    // Leave WhatsApp group endpoint
    app.post('/leave-group', verifyApiKey, async (req, res) => {
        try {
            if (!sockInstance || !isConnected) {
                return res.status(503).json({ error: 'WhatsApp not connected' });
            }
            const { groupJid } = req.body || {};
            if (!groupJid || typeof groupJid !== 'string') {
                return res.status(400).json({ error: 'Missing groupJid' });
            }
            try {
                await waitForSocketReady(20000);
            } catch (e) {
                return res.status(503).json({ error: 'WhatsApp initial sync incomplete', details: e.message });
            }
            const jid = groupJid.endsWith('@g.us') ? groupJid : groupJid;
            try {
                await sockInstance.groupLeave(jid);
                return res.json({ status: 'left' });
            } catch (err) {
                return res.status(500).json({ error: 'Failed to leave group', details: err.message });
            }
        } catch (err) {
            return res.status(500).json({ error: 'Unexpected error', details: err.message });
        }
    });

    const PORT = process.env.PORT || 3000;
    app.listen(PORT, () => {
        console.log(`Express server listening on port ${PORT}`);
    });
}

// Start the application
start().catch(err => {
    console.error("Unhandled Error during initial connectToWhatsApp: ", err);
    process.exit(1);
});
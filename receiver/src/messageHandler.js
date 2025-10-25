const { downloadMediaMessage, proto } = require('@whiskeysockets/baileys');
const { logger } = require('./logger');
const { sendToPHP } = require('./apiClient');
const config = require('./config');
// Note: Avoid importing from whatsappClient at top-level to prevent circular dependency

 
function unwrapMessage(message) {
    let content = message;
    let depth = 0;
    while (content && depth < 5) {
        if (content.ephemeralMessage?.message) {
            content = content.ephemeralMessage.message;
        } else if (content.viewOnceMessageV2?.message) {
            content = content.viewOnceMessageV2.message;
        } else if (content.viewOnceMessageV2Extension?.message) {
            content = content.viewOnceMessageV2Extension.message;
        } else if (content.deviceSentMessage?.message) {
            content = content.deviceSentMessage.message;
        } else {
            break;
        }
        depth++;
    }
    
    // If we still have a messageContextInfo but no actual message, return the original
    if (content?.messageContextInfo && !content?.conversation && !content?.extendedTextMessage && 
        !content?.imageMessage && !content?.videoMessage && !content?.documentMessage && 
        !content?.audioMessage && !content?.locationMessage) {
        return message;
    }
    
    return content;
}

/**
 * Processes incoming message events from Baileys.
 * @param {import('@whiskeysockets/baileys').WASocket} sock - The socket instance.
 * @param {import('@whiskeysockets/baileys').BaileysEventMap['messages.upsert']} m - The message event object.
 */
async function handleMessages(sock, m) {
    try {
        logger.debug({ messageCount: m.messages.length, type: m.type }, 'Processing incoming messages');

        for (const msg of m.messages) {
            // Skip our own messages and focus on notifications
            if (!msg.key.fromMe && m.type === 'notify') {
                const remoteJid = msg.key.remoteJid;
                const isGroup = remoteJid?.endsWith('@g.us');
                if (!msg.message) {
                    logger.debug({ remoteJid, messageId: msg.key.id }, 'Skipping message with no content (likely decryption failed)');
                    continue;
                }
                
                // Fetch sender profile picture and bio for direct chats
                let senderProfilePicture = null;
                let senderBio = null;
                if (!isGroup) {
                    logger.debug({ remoteJid }, 'Attempting to fetch sender profile info for direct chat');
                    const { fetchContactProfilePicture, fetchContactStatus } = require('./whatsappClient');
                    try {
                        senderProfilePicture = await fetchContactProfilePicture(sock, remoteJid);
                        senderBio = await fetchContactStatus(sock, remoteJid);
                        if (typeof senderBio === 'string') {
                            // Trim to backend validation limit
                            senderBio = senderBio.slice(0, 500);
                        }
                        logger.debug({ 
                            remoteJid, 
                            hasPicture: !!senderProfilePicture, 
                            hasBio: !!senderBio,
                            pictureUrl: senderProfilePicture ? senderProfilePicture.substring(0, 50) + '...' : null,
                            bioLength: senderBio ? senderBio.length : 0
                        }, 'Fetched sender profile info');
                    } catch (error) {
                        logger.debug({ remoteJid, error: error.message, stack: error.stack }, 'Could not fetch sender profile info');
                    }
                }
                
                // Extract actual message content if wrapped in messageContextInfo
                let actualMessage = msg.message;
                if (msg.message?.messageContextInfo && !msg.message?.conversation && !msg.message?.extendedTextMessage) {
                    // Check if there's an actual message within messageContextInfo
                    const contextKeys = Object.keys(msg.message);
                    const nonContextKey = contextKeys.find(key => 
                        key !== 'messageContextInfo' && 
                        key !== 'deviceListMetadata' && 
                        key !== 'deviceListMetadataVersion'
                    );
                    
                    if (nonContextKey) {
                        // Use the non-context key as the actual message
                        actualMessage = { [nonContextKey]: msg.message[nonContextKey] };
                        logger.debug({ actualMessageType: nonContextKey }, 'Extracted message from messageContextInfo wrapper');
                    } else {
                        logger.info('messageContextInfo without actual message content');
                        continue; // Skip this message
                    }
                }
                
                
                let senderJid = isGroup
                    ? (msg.key?.participant || msg.participant || undefined)
                    : remoteJid;
                
                // Convert LID to phone JID for group participants
                if (isGroup && senderJid) {
                    // Prefer participantPn if present (already phone@s.whatsapp.net)
                    if (senderJid.endsWith('@lid') && msg.key && msg.key.participantPn) {
                        logger.debug({ from: senderJid, to: msg.key.participantPn }, 'Using participantPn as senderJid');
                        try {
                            const { recordLidToPhone } = require('./whatsappClient');
                            recordLidToPhone(senderJid, msg.key.participantPn);
                        } catch (_) {}
                        senderJid = msg.key.participantPn;
                    } else if (senderJid.endsWith('@lid')) {
                        // Try contact store conversion as fallback
                        try {
                            const { convertLidToPhoneJid } = require('./whatsappClient');
                            if (typeof convertLidToPhoneJid === 'function') {
                                const converted = convertLidToPhoneJid(sock, senderJid);
                                if (converted !== senderJid) {
                                    logger.debug({ from: senderJid, to: converted }, 'Converted LID to phone JID');
                                }
                                senderJid = converted;
                            } else {
                                logger.debug({ senderJid }, 'convertLidToPhoneJid not a function, using raw senderJid');
                            }
                        } catch (e) {
                            logger.debug({ error: e.message, senderJid }, 'convertLidToPhoneJid not available, using raw senderJid');
                        }
                    }
                }

                
                actualMessage = unwrapMessage(actualMessage);

                logger.info({
                    from: remoteJid,
                    isGroup,
                    messageId: msg.key.id,
                    messageType: Object.keys(actualMessage || {})[0]
                }, 'New message received');
                
                // Debug: Log full message structure for troubleshooting
                if (!actualMessage || Object.keys(actualMessage).length === 0) {
                    logger.debug({
                        remoteJid,
                        messageId: msg.key.id,
                        fullMessage: JSON.stringify(msg.message, null, 2)
                    }, 'Message structure for debugging');
                }

                // Handle different message types
                try {
                    // 1. Simple text messages
                    if (actualMessage?.conversation) {
                        logger.debug({ remoteJid, messageId: msg.key.id, hasSenderProfile: !!senderProfilePicture }, 'Handling text message');
                        await handleTextMessage(remoteJid, actualMessage.conversation, {}, msg.key.id, senderJid, senderProfilePicture, senderBio);
                    }
                    // 2. Extended text messages (e.g., with context)
                    else if (actualMessage?.extendedTextMessage) {
                        const { text, contextInfo } = actualMessage.extendedTextMessage;
                        await handleTextMessage(remoteJid, text, contextInfo, msg.key.id, senderJid, senderProfilePicture, senderBio);
                    }
                    // 3. Image messages
                    else if (actualMessage?.imageMessage) {
                        // Update msg.message to use the extracted message
                        if (actualMessage !== msg.message) {
                            msg.message = actualMessage;
                        }
                        await handleImageMessage(sock, msg, remoteJid, senderJid, senderProfilePicture, senderBio);
                    }
                    // 4. Video messages
                    else if (actualMessage?.videoMessage) {
                        if (actualMessage !== msg.message) {
                            msg.message = actualMessage;
                        }
                        await handleVideoMessage(sock, msg, remoteJid, senderJid, senderProfilePicture, senderBio);
                    }
                    // 5. Document messages
                    else if (actualMessage?.documentMessage) {
                        if (actualMessage !== msg.message) {
                            msg.message = actualMessage;
                        }
                        await handleDocumentMessage(sock, msg, remoteJid, senderJid, senderProfilePicture, senderBio);
                    }
                    // 6. Audio messages
                    else if (actualMessage?.audioMessage) {
                        if (actualMessage !== msg.message) {
                            msg.message = actualMessage;
                        }
                        await handleAudioMessage(sock, msg, remoteJid, senderJid, senderProfilePicture, senderBio);
                    }
                    // 7. Location messages
                    else if (actualMessage?.locationMessage) {
                        await handleLocationMessage(msg, remoteJid, senderJid, senderProfilePicture, senderBio);
                    }
                    // 8. Reaction messages
                    else if (actualMessage?.reactionMessage) {
                        await handleReactionMessage(msg, remoteJid);
                    }
                    // 9. Edited messages
                    else if (actualMessage?.editedMessage) {
                        await handleEditedMessage(msg, remoteJid);
                    }
                    // 10. Protocol messages (deletions, etc.)
                    else if (actualMessage?.protocolMessage) {
                        await handleProtocolMessage(msg, remoteJid);
                    }
                    // 11. Sender key distribution (group encryption key setup)
                    else if (actualMessage?.senderKeyDistributionMessage) {
                        logger.debug({ remoteJid, messageId: msg.key.id }, 'Received sender key distribution message');
                    }
                    // 12. Unsupported message types
                    else {
                        const messageType = Object.keys(actualMessage || {})[0];
                        logger.info({ 
                            messageType,
                            remoteJid,
                            messageId: msg.key.id,
                            actualMessageKeys: Object.keys(actualMessage || {}),
                            fullMessage: JSON.stringify(actualMessage, null, 2)
                        }, 'Unhandled message type');
                    }
                } catch (error) {
                    logger.error({
                        error: error.message,
                        stack: error.stack,
                        messageId: msg.key.id,
                        messageType: Object.keys(msg.message || {})[0]
                    }, 'Error processing message');
                }
            }
        }
    } catch (error) {
        logger.error({ error: error?.message || String(error), stack: error?.stack }, 'Unexpected error in handleMessages');
    }
}

/**
 * Extract content from quoted message
 */
function extractQuotedContent(quotedMessage) {
    if (!quotedMessage) return '';
    
    // Try different message types
    if (quotedMessage.conversation) return quotedMessage.conversation;
    if (quotedMessage.extendedTextMessage?.text) return quotedMessage.extendedTextMessage.text;
    if (quotedMessage.imageMessage?.caption) return quotedMessage.imageMessage.caption || '[Image]';
    if (quotedMessage.videoMessage?.caption) return quotedMessage.videoMessage.caption || '[Video]';
    if (quotedMessage.documentMessage?.caption) return quotedMessage.documentMessage.caption || '[Document]';
    if (quotedMessage.audioMessage) return '[Audio]';
    
    return '[Message]';
}

/**
 * Handles text messages.
 * @param {string} remoteJid - The sender's JID.
 * @param {string} text - The message text.
 * @param {object} [contextInfo] - Additional context information.
 * @param {string} [messageId] - The message ID.
 * @param {string} [senderJid] - The sender's JID (for groups).
 * @param {string} [senderProfilePicture] - The sender's profile picture URL.
 * @param {string} [senderBio] - The sender's bio/status.
 */
async function handleTextMessage(remoteJid, text, contextInfo = {}, messageId = null, senderJid = null, senderProfilePicture = null, senderBio = null) {
    logger.debug({ 
        remoteJid, 
        textLength: text.length, 
        hasContext: !!contextInfo, 
        messageId,
        hasSenderProfilePicture: !!senderProfilePicture,
        hasSenderBio: !!senderBio
    }, 'Processing text message');
    
    // Extract quoted message info if present
    let quotedMessageData = null;
    if (contextInfo?.quotedMessage) {
        let quotedSender = contextInfo.participant || remoteJid;
        // Note: We don't convert LID here since we don't have socket context in this function
        quotedMessageData = {
            quotedMessageId: contextInfo.stanzaId,
            quotedContent: extractQuotedContent(contextInfo.quotedMessage),
            quotedSender: quotedSender
        };
        logger.debug({ quotedMessageData }, 'Extracted quoted message data');
    }
    
    logger.debug({
        remoteJid,
        messageId,
        sendingProfileData: {
            hasPicture: !!senderProfilePicture,
            hasBio: !!senderBio
        }
    }, 'Sending message to PHP backend with profile data');
    
    const messageData = {
        from: remoteJid,
        chat: remoteJid,
        senderJid: senderJid || undefined,
        type: 'text',
        body: text,
        messageId: messageId,
        contextInfo: contextInfo || undefined,
        quotedMessage: quotedMessageData,
        senderProfilePictureUrl: senderProfilePicture || undefined,
        senderBio: (senderBio ?? undefined)
    };
    
    logger.debug({ 
        messageData: { 
            ...messageData, 
            media: messageData.media ? '[base64 data]' : null,
            senderProfilePictureUrl: messageData.senderProfilePictureUrl ? '[URL present]' : null,
            senderBio: messageData.senderBio ? '[Bio present]' : null
        }
    }, 'Sending message data to backend');
    
    await sendToPHP(messageData);
    
    logger.debug({ remoteJid, messageId }, 'Message sent to PHP backend');
}

/**
 * Handles image messages.
 * @param {object} sock - The socket instance.
 * @param {object} msg - The message object.
 * @param {string} remoteJid - The sender's JID.
 * @param {string} [senderJid] - The sender's JID (for groups).
 * @param {string} [senderProfilePicture] - The sender's profile picture URL.
 * @param {string} [senderBio] - The sender's bio/status.
 */
async function handleImageMessage(sock, msg, remoteJid, senderJid = null, senderProfilePicture = null, senderBio = null) {
    logger.debug({ remoteJid }, 'Processing image message');
    
    try {
        const buffer = await downloadMediaMessage(
            msg,
            'buffer',
            {},
            {
                logger: {
                    debug: (msg) => logger.debug({}, msg),
                    info: (msg) => logger.info({}, msg),
                    warn: (msg) => logger.warn({}, msg),
                    error: (msg) => logger.error({}, msg)
                },
                reuploadRequest: sock.updateMediaMessage
            }
        );

        logger.debug({ remoteJid, bufferSize: buffer.length }, 'Downloaded image');
        
        const base64Image = buffer.toString('base64');
        const caption = msg.message.imageMessage.caption || '';
        const mimetype = msg.message.imageMessage.mimetype || 'image/jpeg';
        const contextInfo = msg.message.imageMessage.contextInfo;
        
        // Extract quoted message info if present
        let quotedMessageData = null;
        if (contextInfo?.quotedMessage) {
            let quotedSender = contextInfo.participant || remoteJid;
            quotedMessageData = {
                quotedMessageId: contextInfo.stanzaId,
                quotedContent: extractQuotedContent(contextInfo.quotedMessage),
                quotedSender: quotedSender
            };
        }

        const messageData = {
            from: remoteJid,
            chat: remoteJid,
            senderJid: senderJid || undefined,
            type: 'image',
            body: caption,
            media: base64Image,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            quotedMessage: quotedMessageData,
            senderProfilePictureUrl: senderProfilePicture || undefined,
            senderBio: (senderBio ?? undefined)
        };
        
        logger.debug({ 
            messageData: { 
                ...messageData, 
                media: messageData.media ? '[base64 data]' : null,
                senderProfilePictureUrl: messageData.senderProfilePictureUrl ? '[URL present]' : null,
                senderBio: messageData.senderBio ? '[Bio present]' : null
            }
        }, 'Sending message data to backend');
        
        await sendToPHP(messageData);
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            remoteJid,
            messageId: msg.key.id 
        }, 'Error processing image message');
        throw error;
    }
}

/**
 * Handles video messages.
 * @param {object} sock - The socket instance.
 * @param {object} msg - The message object.
 * @param {string} remoteJid - The sender's JID.
 * @param {string} [senderJid] - The sender's JID (for groups).
 * @param {string} [senderProfilePicture] - The sender's profile picture URL.
 * @param {string} [senderBio] - The sender's bio/status.
 */
async function handleVideoMessage(sock, msg, remoteJid, senderJid = null, senderProfilePicture = null, senderBio = null) {
    logger.debug({ remoteJid }, 'Processing video message');
    
    try {
        const buffer = await downloadMediaMessage(
            msg,
            'buffer',
            {},
            {
                logger: {
                    debug: (msg) => logger.debug({}, msg),
                    info: (msg) => logger.info({}, msg),
                    warn: (msg) => logger.warn({}, msg),
                    error: (msg) => logger.error({}, msg)
                },
                reuploadRequest: sock.updateMediaMessage
            }
        );

        logger.debug({ remoteJid, bufferSize: buffer.length }, 'Downloaded video');
        
        const base64Video = buffer.toString('base64');
        const caption = msg.message.videoMessage.caption || '';
        const mimetype = msg.message.videoMessage.mimetype || 'video/mp4';
        const contextInfo = msg.message.videoMessage.contextInfo;
        
        // Extract quoted message info if present
        let quotedMessageData = null;
        if (contextInfo?.quotedMessage) {
            let quotedSender = contextInfo.participant || remoteJid;
            quotedMessageData = {
                quotedMessageId: contextInfo.stanzaId,
                quotedContent: extractQuotedContent(contextInfo.quotedMessage),
                quotedSender: quotedSender
            };
        }

        const messageData = {
            from: remoteJid,
            chat: remoteJid,
            senderJid: senderJid || undefined,
            type: 'video',
            body: caption,
            media: base64Video,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.videoMessage.fileLength,
            quotedMessage: quotedMessageData,
            senderProfilePictureUrl: senderProfilePicture || undefined,
            senderBio: senderBio || undefined
        };
        
        logger.debug({ 
            messageData: { 
                ...messageData, 
                media: messageData.media ? '[base64 data]' : null,
                senderProfilePictureUrl: messageData.senderProfilePictureUrl ? '[URL present]' : null,
                senderBio: messageData.senderBio ? '[Bio present]' : null
            }
        }, 'Sending message data to backend');
        
        await sendToPHP(messageData);
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            remoteJid,
            messageId: msg.key.id 
        }, 'Error processing video message');
        throw error;
    }
}

/**
 * Handles document messages.
 * @param {object} sock - The socket instance.
 * @param {object} msg - The message object.
 * @param {string} remoteJid - The sender's JID.
 * @param {string} [senderJid] - The sender's JID (for groups).
 * @param {string} [senderProfilePicture] - The sender's profile picture URL.
 * @param {string} [senderBio] - The sender's bio/status.
 */
async function handleDocumentMessage(sock, msg, remoteJid, senderJid = null, senderProfilePicture = null, senderBio = null) {
    logger.debug({ remoteJid }, 'Processing document message');
    
    try {
        const buffer = await downloadMediaMessage(
            msg,
            'buffer',
            {},
            {
                logger: {
                    debug: (msg) => logger.debug({}, msg),
                    info: (msg) => logger.info({}, msg),
                    warn: (msg) => logger.warn({}, msg),
                    error: (msg) => logger.error({}, msg)
                },
                reuploadRequest: sock.updateMediaMessage
            }
        );

        logger.debug({ remoteJid, bufferSize: buffer.length }, 'Downloaded document');
        
        const base64Doc = buffer.toString('base64');
        const fileName = msg.message.documentMessage.fileName || 'document';
        const mimetype = msg.message.documentMessage.mimetype || 'application/octet-stream';
        const caption = msg.message.documentMessage.caption || '';
        const contextInfo = msg.message.documentMessage.contextInfo;
        
        // Extract quoted message info if present
        let quotedMessageData = null;
        if (contextInfo?.quotedMessage) {
            let quotedSender = contextInfo.participant || remoteJid;
            quotedMessageData = {
                quotedMessageId: contextInfo.stanzaId,
                quotedContent: extractQuotedContent(contextInfo.quotedMessage),
                quotedSender: quotedSender
            };
        }

        const messageData = {
            from: remoteJid,
            chat: remoteJid,
            senderJid: senderJid || undefined,
            type: 'document',
            body: caption,
            fileName: fileName,
            media: base64Doc,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.documentMessage.fileLength,
            quotedMessage: quotedMessageData,
            senderProfilePictureUrl: senderProfilePicture || undefined,
            senderBio: senderBio || undefined
        };
        
        logger.debug({ 
            messageData: { 
                ...messageData, 
                media: messageData.media ? '[base64 data]' : null,
                senderProfilePictureUrl: messageData.senderProfilePictureUrl ? '[URL present]' : null,
                senderBio: messageData.senderBio ? '[Bio present]' : null
            }
        }, 'Sending message data to backend');
        
        await sendToPHP(messageData);
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            remoteJid,
            messageId: msg.key.id 
        }, 'Error processing document message');
        throw error;
    }
}

/**
 * Handles audio messages.
 * @param {object} sock - The socket instance.
 * @param {object} msg - The message object.
 * @param {string} remoteJid - The sender's JID.
 * @param {string} [senderJid] - The sender's JID (for groups).
 * @param {string} [senderProfilePicture] - The sender's profile picture URL.
 * @param {string} [senderBio] - The sender's bio/status.
 */
async function handleAudioMessage(sock, msg, remoteJid, senderJid = null, senderProfilePicture = null, senderBio = null) {
    logger.debug({ remoteJid }, 'Processing audio message');
    
    try {
        const buffer = await downloadMediaMessage(
            msg,
            'buffer',
            {},
            {
                logger: {
                    debug: (msg) => logger.debug({}, msg),
                    info: (msg) => logger.info({}, msg),
                    warn: (msg) => logger.warn({}, msg),
                    error: (msg) => logger.error({}, msg)
                },
                reuploadRequest: sock.updateMediaMessage
            }
        );

        logger.debug({ remoteJid, bufferSize: buffer.length }, 'Downloaded audio');
        
        const base64Audio = buffer.toString('base64');
        const mimetype = msg.message.audioMessage.mimetype || 'audio/ogg; codecs=opus';
        const contextInfo = msg.message.audioMessage.contextInfo;
        
        // Extract quoted message info if present
        let quotedMessageData = null;
        if (contextInfo?.quotedMessage) {
            let quotedSender = contextInfo.participant || remoteJid;
            quotedMessageData = {
                quotedMessageId: contextInfo.stanzaId,
                quotedContent: extractQuotedContent(contextInfo.quotedMessage),
                quotedSender: quotedSender
            };
        }

        const messageData = {
            from: remoteJid,
            chat: remoteJid,
            senderJid: senderJid || undefined,
            type: 'audio',
            body: '', // Audio messages don't have captions, but backend expects a content field
            media: base64Audio,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.audioMessage.fileLength,
            quotedMessage: quotedMessageData,
            senderProfilePictureUrl: senderProfilePicture || undefined,
            senderBio: senderBio || undefined
        };
        
        logger.debug({ 
            messageData: { 
                ...messageData, 
                media: messageData.media ? '[base64 data]' : null,
                senderProfilePictureUrl: messageData.senderProfilePictureUrl ? '[URL present]' : null,
                senderBio: messageData.senderBio ? '[Bio present]' : null
            }
        }, 'Sending message data to backend');
        
        await sendToPHP(messageData);
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            remoteJid,
            messageId: msg.key.id 
        }, 'Error processing audio message');
        throw error;
    }
}

/**
 * Handles location messages.
 * @param {object} msg - The message object.
 * @param {string} remoteJid - The sender's JID.
 * @param {string} [senderJid] - The sender's JID (for groups).
 * @param {string} [senderProfilePicture] - The sender's profile picture URL.
 * @param {string} [senderBio] - The sender's bio/status.
 */
async function handleLocationMessage(msg, remoteJid, senderJid = null, senderProfilePicture = null, senderBio = null) {
    try {
        const location = msg.message.locationMessage;
        logger.debug({ remoteJid, location }, 'Processing location message');

        const messageData = {
            from: remoteJid,
            chat: remoteJid,
            senderJid: senderJid || undefined,
            type: 'location',
            body: location.name || 'Shared Location',
            latitude: location.degreesLatitude,
            longitude: location.degreesLongitude,
            name: location.name || 'Shared Location',
            address: location.address || '',
            url: location.url || '',
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            senderProfilePictureUrl: senderProfilePicture || undefined,
            senderBio: senderBio || undefined
        };
        
        logger.debug({ 
            messageData: { 
                ...messageData, 
                media: messageData.media ? '[base64 data]' : null,
                senderProfilePictureUrl: messageData.senderProfilePictureUrl ? '[URL present]' : null,
                senderBio: messageData.senderBio ? '[Bio present]' : null
            }
        }, 'Sending message data to backend');
        
        await sendToPHP(messageData);
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            remoteJid,
            messageId: msg.key.id 
        }, 'Error processing location message');
        throw error;
    }
}

/**
 * Handles reaction messages.
 * @param {object} msg - The message object.
 * @param {string} remoteJid - The sender's JID.
 */
async function handleReactionMessage(msg, remoteJid) {
    try {
        const reaction = msg.message.reactionMessage;
        logger.debug({ remoteJid, reaction }, 'Processing reaction message');

        // Extract the message ID that was reacted to
        const reactedMessageId = reaction.key?.id;
        const emoji = reaction.text || ''; // Empty string means reaction removed
        let senderJid = reaction.key?.participant || remoteJid;
        // Note: We don't have socket context in this function to convert LID

        if (!reactedMessageId) {
            logger.warn({ remoteJid }, 'Reaction message missing target message ID');
            return;
        }

        const messageData = {
            from: remoteJid,
            type: 'reaction',
            reactedMessageId: reactedMessageId,
            emoji: emoji,
            senderJid: senderJid,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id
        };
        
        logger.debug({ 
            messageData: { 
                ...messageData, 
                media: messageData.media ? '[base64 data]' : null,
                senderProfilePictureUrl: messageData.senderProfilePictureUrl ? '[URL present]' : null,
                senderBio: messageData.senderBio ? '[Bio present]' : null
            }
        }, 'Sending message data to backend');
        
        await sendToPHP(messageData);
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            remoteJid,
            messageId: msg.key.id 
        }, 'Error processing reaction message');
        throw error;
    }
}

/**
 * Handle edited message
 * @param {Object} msg - The message object
 * @param {string} remoteJid - The remote JID (chat ID)
 */
async function handleEditedMessage(msg, remoteJid) {
    try {
        const editedMessage = msg.message.editedMessage;
        
        // Track this edit message ID to skip status updates
        const { addEditMessageId } = require('./whatsappClient');
        addEditMessageId(msg.key.id);
        
        // The edited message structure contains the protocol message with the original ID
        // and the actual new content in the editedMessage.message
        const protocolMessage = editedMessage.message?.protocolMessage;
        const originalMessageId = protocolMessage?.key?.id;
        
        // Extract the new content - it's in the editedMessage.message, not in protocolMessage
        // Try different possible locations for the content
        const newContent = editedMessage.message?.conversation 
                        || editedMessage.message?.extendedTextMessage?.text
                        || protocolMessage?.editedMessage?.conversation
                        || protocolMessage?.editedMessage?.extendedTextMessage?.text
                        || '';
        
        logger.info({ 
            originalMessageId,
            editMessageId: msg.key.id,
            newContent,
            editedMessageStructure: JSON.stringify(editedMessage.message),
            remoteJid 
        }, 'Processing edited message');
        
        if (!originalMessageId) {
            logger.warn({ editedMessage }, 'No original message ID found in edited message');
            return;
        }
        
        if (!newContent) {
            logger.warn({ originalMessageId, editedMessage }, 'No new content found in edited message');
            return;
        }
        
        // Notify backend about the edit
        const apiClient = require('./apiClient');
        await apiClient.notifyMessageEdited(originalMessageId, newContent);
        
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            remoteJid,
            messageId: msg.key.id 
        }, 'Error processing edited message');
    }
}

/**
 * Handle protocol message (deletions, etc.)
 * @param {Object} msg - The message object
 * @param {string} remoteJid - The remote JID (chat ID)
 */
async function handleProtocolMessage(msg, remoteJid) {
    try {
        const protocolMessage = msg.message.protocolMessage;
        
        // Track this protocol message ID to skip status updates
        const { addProtocolMessageId } = require('./whatsappClient');
        addProtocolMessageId(msg.key.id);
        
        // Check if this is a message deletion
        if (protocolMessage.type === 0) { // REVOKE type
            const deletedMessageId = protocolMessage.key?.id;
            
            logger.info({ 
                deletedMessageId,
                protocolMessageId: msg.key.id,
                remoteJid 
            }, 'Processing message deletion');
            
            if (!deletedMessageId) {
                logger.warn('No message ID found in protocol message');
                return;
            }
            
            // Notify backend about the deletion
            const apiClient = require('./apiClient');
            await apiClient.notifyMessageDeleted(deletedMessageId);
        } else {
            logger.debug({ 
                type: protocolMessage.type,
                remoteJid 
            }, 'Unhandled protocol message type');
        }
        
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            remoteJid,
            messageId: msg.key.id 
        }, 'Error processing protocol message');
    }
}

module.exports = { 
    handleMessages, 
    handleTextMessage, 
    handleImageMessage, 
    handleVideoMessage, 
    handleDocumentMessage, 
    handleAudioMessage, 
    handleLocationMessage,
    handleReactionMessage,
    handleEditedMessage,
    handleProtocolMessage
};
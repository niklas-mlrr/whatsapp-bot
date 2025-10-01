const { downloadMediaMessage, proto } = require('@whiskeysockets/baileys');
const { logger } = require('./logger');
const { sendToPHP } = require('./apiClient');
const config = require('./config');

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
                
                // Extract actual message content if wrapped in messageContextInfo
                let actualMessage = msg.message;
                if (msg.message?.messageContextInfo) {
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
                
                logger.info({
                    from: remoteJid,
                    isGroup,
                    messageId: msg.key.id,
                    messageType: Object.keys(actualMessage || {})[0]
                }, 'New message received');

                // Handle different message types
                try {
                    // 1. Simple text messages
                    if (actualMessage?.conversation) {
                        await handleTextMessage(remoteJid, actualMessage.conversation);
                    }
                    // 2. Extended text messages (e.g., with context)
                    else if (actualMessage?.extendedTextMessage) {
                        const { text, contextInfo } = actualMessage.extendedTextMessage;
                        await handleTextMessage(remoteJid, text, contextInfo);
                    }
                    // 3. Image messages
                    else if (actualMessage?.imageMessage) {
                        // Update msg.message to use the extracted message
                        if (actualMessage !== msg.message) {
                            msg.message = actualMessage;
                        }
                        await handleImageMessage(sock, msg, remoteJid);
                    }
                    // 4. Video messages
                    else if (actualMessage?.videoMessage) {
                        if (actualMessage !== msg.message) {
                            msg.message = actualMessage;
                        }
                        await handleVideoMessage(sock, msg, remoteJid);
                    }
                    // 5. Document messages
                    else if (actualMessage?.documentMessage) {
                        if (actualMessage !== msg.message) {
                            msg.message = actualMessage;
                        }
                        await handleDocumentMessage(sock, msg, remoteJid);
                    }
                    // 6. Audio messages
                    else if (actualMessage?.audioMessage) {
                        if (actualMessage !== msg.message) {
                            msg.message = actualMessage;
                        }
                        await handleAudioMessage(sock, msg, remoteJid);
                    }
                    // 7. Location messages
                    else if (actualMessage?.locationMessage) {
                        await handleLocationMessage(msg, remoteJid);
                    }
                    // 8. Unsupported message types
                    else {
                        const messageType = Object.keys(actualMessage || {})[0];
                        logger.info({ messageType }, 'Unhandled message type');
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
        logger.error({ error }, 'Unexpected error in handleMessages');
    }
}

/**
 * Handles text messages.
 * @param {string} remoteJid - The sender's JID.
 * @param {string} text - The message text.
 * @param {object} [contextInfo] - Additional context information.
 */
async function handleTextMessage(remoteJid, text, contextInfo = {}) {
    logger.debug({ remoteJid, textLength: text.length, hasContext: !!contextInfo }, 'Processing text message');
    
    await sendToPHP({
        from: remoteJid,
        type: 'text',
        body: text,
        contextInfo: contextInfo || undefined
    });
}

/**
 * Handles image messages.
 * @param {object} sock - The socket instance.
 * @param {object} msg - The message object.
 * @param {string} remoteJid - The sender's JID.
 */
async function handleImageMessage(sock, msg, remoteJid) {
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

        await sendToPHP({
            from: remoteJid,
            type: 'image',
            body: caption,
            media: base64Image,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id
        });
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
 */
async function handleVideoMessage(sock, msg, remoteJid) {
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

        await sendToPHP({
            from: remoteJid,
            type: 'video',
            body: caption,
            media: base64Video,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.videoMessage.fileLength
        });
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
 */
async function handleDocumentMessage(sock, msg, remoteJid) {
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

        await sendToPHP({
            from: remoteJid,
            type: 'document',
            body: caption,
            fileName: fileName,
            media: base64Doc,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.documentMessage.fileLength
        });
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
 */
async function handleAudioMessage(sock, msg, remoteJid) {
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

        await sendToPHP({
            from: remoteJid,
            type: 'audio',
            body: '', // Audio messages don't have captions, but backend expects a content field
            media: base64Audio,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.audioMessage.fileLength
        });
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
 */
async function handleLocationMessage(msg, remoteJid) {
    try {
        const location = msg.message.locationMessage;
        logger.debug({ remoteJid, location }, 'Processing location message');

        await sendToPHP({
            from: remoteJid,
            type: 'location',
            body: location.name || 'Shared Location',
            latitude: location.degreesLatitude,
            longitude: location.degreesLongitude,
            name: location.name || 'Shared Location',
            address: location.address || '',
            url: location.url || '',
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id
        });
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

module.exports = { 
    handleMessages, 
    handleTextMessage, 
    handleImageMessage, 
    handleVideoMessage, 
    handleDocumentMessage, 
    handleAudioMessage, 
    handleLocationMessage 
};
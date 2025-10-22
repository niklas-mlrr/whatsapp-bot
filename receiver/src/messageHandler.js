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
                        await handleTextMessage(remoteJid, actualMessage.conversation, {}, msg.key.id);
                    }
                    // 2. Extended text messages (e.g., with context)
                    else if (actualMessage?.extendedTextMessage) {
                        const { text, contextInfo } = actualMessage.extendedTextMessage;
                        await handleTextMessage(remoteJid, text, contextInfo, msg.key.id);
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
                    // 11. Unsupported message types
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
 */
async function handleTextMessage(remoteJid, text, contextInfo = {}, messageId = null) {
    logger.debug({ remoteJid, textLength: text.length, hasContext: !!contextInfo, messageId }, 'Processing text message');
    
    // Extract quoted message info if present
    let quotedMessageData = null;
    if (contextInfo?.quotedMessage) {
        quotedMessageData = {
            quotedMessageId: contextInfo.stanzaId,
            quotedContent: extractQuotedContent(contextInfo.quotedMessage),
            quotedSender: contextInfo.participant || remoteJid
        };
        logger.debug({ quotedMessageData }, 'Extracted quoted message data');
    }
    
    await sendToPHP({
        from: remoteJid,
        type: 'text',
        body: text,
        messageId: messageId,
        contextInfo: contextInfo || undefined,
        quotedMessage: quotedMessageData
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
        const contextInfo = msg.message.imageMessage.contextInfo;
        
        // Extract quoted message info if present
        let quotedMessageData = null;
        if (contextInfo?.quotedMessage) {
            quotedMessageData = {
                quotedMessageId: contextInfo.stanzaId,
                quotedContent: extractQuotedContent(contextInfo.quotedMessage),
                quotedSender: contextInfo.participant || remoteJid
            };
        }

        await sendToPHP({
            from: remoteJid,
            type: 'image',
            body: caption,
            media: base64Image,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            quotedMessage: quotedMessageData
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
        const contextInfo = msg.message.videoMessage.contextInfo;
        
        // Extract quoted message info if present
        let quotedMessageData = null;
        if (contextInfo?.quotedMessage) {
            quotedMessageData = {
                quotedMessageId: contextInfo.stanzaId,
                quotedContent: extractQuotedContent(contextInfo.quotedMessage),
                quotedSender: contextInfo.participant || remoteJid
            };
        }

        await sendToPHP({
            from: remoteJid,
            type: 'video',
            body: caption,
            media: base64Video,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.videoMessage.fileLength,
            quotedMessage: quotedMessageData
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
        const contextInfo = msg.message.documentMessage.contextInfo;
        
        // Extract quoted message info if present
        let quotedMessageData = null;
        if (contextInfo?.quotedMessage) {
            quotedMessageData = {
                quotedMessageId: contextInfo.stanzaId,
                quotedContent: extractQuotedContent(contextInfo.quotedMessage),
                quotedSender: contextInfo.participant || remoteJid
            };
        }

        await sendToPHP({
            from: remoteJid,
            type: 'document',
            body: caption,
            fileName: fileName,
            media: base64Doc,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.documentMessage.fileLength,
            quotedMessage: quotedMessageData
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
        const contextInfo = msg.message.audioMessage.contextInfo;
        
        // Extract quoted message info if present
        let quotedMessageData = null;
        if (contextInfo?.quotedMessage) {
            quotedMessageData = {
                quotedMessageId: contextInfo.stanzaId,
                quotedContent: extractQuotedContent(contextInfo.quotedMessage),
                quotedSender: contextInfo.participant || remoteJid
            };
        }

        await sendToPHP({
            from: remoteJid,
            type: 'audio',
            body: '', // Audio messages don't have captions, but backend expects a content field
            media: base64Audio,
            mimetype: mimetype,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id,
            mediaSize: msg.message.audioMessage.fileLength,
            quotedMessage: quotedMessageData
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
        const senderJid = reaction.key?.participant || remoteJid;

        if (!reactedMessageId) {
            logger.warn({ remoteJid }, 'Reaction message missing target message ID');
            return;
        }

        await sendToPHP({
            from: remoteJid,
            type: 'reaction',
            reactedMessageId: reactedMessageId,
            emoji: emoji,
            senderJid: senderJid,
            messageTimestamp: msg.messageTimestamp,
            messageId: msg.key.id
        });
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
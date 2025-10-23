const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers } = require('@whiskeysockets/baileys');
const { Boom } = require('@hapi/boom');
const pino = require('pino');
const config = require('./config');
const { logger } = require('./logger');
const { handleMessages } = require('./messageHandler');

const msgRetryCounterCache = (() => {
    const cache = new Map();
    const ttlMs = 5 * 60 * 1000; // 5 minutes
    return {
        get: (key) => cache.get(key),
        set: (key, val) => {
            cache.set(key, val);
            setTimeout(() => cache.delete(key), ttlMs).unref?.();
        },
        del: (key) => cache.delete(key),
    };
})();

let isReconnecting = false;
let currentSocket = null;
let reconnectCallback = null;
let reconnectTimeout = null;

// Track edit message IDs to skip their status updates
const editMessageIds = new Set();
const protocolMessageIds = new Set();

/**
 * Add a message ID to the edit tracking set
 * @param {string} messageId - The message ID to track
 */
function addEditMessageId(messageId) {
    editMessageIds.add(messageId);
    logger.debug({ messageId, totalTracked: editMessageIds.size }, 'Added edit message ID to tracking');
    // Auto-remove after 5 minutes to prevent memory leak
    // Edit messages can receive status updates for a while
    setTimeout(() => {
        editMessageIds.delete(messageId);
        logger.debug({ messageId }, 'Removed edit message ID from tracking');
    }, 300000); // 5 minutes
}

/**
 * Add a message ID to the protocol message tracking set
 * @param {string} messageId - The message ID to track
 */
function addProtocolMessageId(messageId) {
    protocolMessageIds.add(messageId);
    logger.debug({ messageId, totalTracked: protocolMessageIds.size }, 'Added protocol message ID to tracking');
    // Auto-remove after 5 minutes to prevent memory leak
    setTimeout(() => {
        protocolMessageIds.delete(messageId);
        logger.debug({ messageId }, 'Removed protocol message ID from tracking');
    }, 300000); // 5 minutes
}

/**
 * Set a callback to be called when the socket reconnects
 * @param {Function} callback - Function to call with the new socket instance
 */
function setReconnectCallback(callback) {
    reconnectCallback = callback;
}

/**
 * Fetch contact profile picture URL from WhatsApp
 * @param {object} sock - The socket instance
 * @param {string} jid - The contact JID (phone@s.whatsapp.net or group@g.us)
 * @returns {Promise<string|null>} The profile picture URL or null
 */
async function fetchContactProfilePicture(sock, jid) {
    try {
        const profilePicture = await sock.profilePictureUrl(jid, 'image');
        return profilePicture || null;
    } catch (error) {
        logger.debug({ jid, error: error.message }, 'Could not fetch profile picture');
        return null;
    }
}

/**
 * Fetch contact status/bio from WhatsApp
 * @param {object} sock - The socket instance
 * @param {string} jid - The contact JID (phone@s.whatsapp.net)
 * @returns {Promise<string|null>} The status/bio or null
 */
async function fetchContactStatus(sock, jid) {
    try {
        const status = await sock.fetchStatus(jid);
        return status?.status || null;
    } catch (error) {
        logger.debug({ jid, error: error.message }, 'Could not fetch contact status');
        return null;
    }
}

/**
 * Convert LID to phone number JID using socket's contact store
 * @param {object} sock - The socket instance
 * @param {string} jid - The JID (could be LID like "123@lid" or already a phone JID)
 * @returns {string} The phone number JID or original JID if conversion fails
 */
function convertLidToPhoneJid(sock, jid) {
    if (!jid) return jid;
    
    if (!jid.endsWith('@lid')) {
        return jid;
    }
    
    try {
        const contacts = sock.contacts || {};
        for (const [contactJid, contactInfo] of Object.entries(contacts)) {
            if (contactInfo?.id === jid) {
                logger.debug({ lid: jid, phoneJid: contactJid }, 'Converted LID to phone JID');
                return contactJid;
            }
        }
    } catch (error) {
        logger.debug({ jid, error: error.message }, 'Could not convert LID to phone JID');
    }
    
    return jid;
}

/**
 * Establishes a connection to the WhatsApp Web service.
 * @returns {Promise<object>} The WhatsApp socket instance.
 */
async function connectToWhatsApp() {
    // Prevent multiple simultaneous connection attempts
    if (isReconnecting) {
        logger.warn('Connection attempt already in progress, skipping...');
        return currentSocket;
    }

    // If we already have an active socket, reuse it instead of opening a new one
    if (currentSocket?.ws?.readyState === 1) { // 1 === WebSocket.OPEN
        logger.debug('Existing WhatsApp socket is active, reusing current instance');
        return currentSocket;
    }

    try {
        isReconnecting = true;
        logger.info('Initializing WhatsApp client...');
        
        // Use file-based authentication state
        const { state, saveCreds } = await useMultiFileAuthState(config.whatsapp.authDir);
        logger.debug(`Using auth directory: ${config.whatsapp.authDir}`);

        // Get the latest Baileys version
        const { version, isLatest } = await fetchLatestBaileysVersion();
        logger.info(`Using Baileys version ${version}, isLatest: ${isLatest}`);

        // Configure the WhatsApp socket
        const sock = makeWASocket({
            browser: Browsers.macOS(config.whatsapp.clientName),
            version,
            auth: state,
            msgRetryCounterCache,
            logger: pino({
                level: config.logging.level,
                transport: config.nodeEnv === 'development' ? {
                    target: 'pino-pretty',
                    options: {
                        colorize: true,
                        translateTime: 'SYS:standard',
                        ignore: 'pid,hostname',
                    },
                } : undefined,
            }),
            syncFullHistory: true,
            printQRInTerminal: true,
            markOnlineOnConnect: true,
            generateHighQualityLinkPreview: true,
            keepAliveIntervalMs: 30000, // Send keepalive every 30 seconds
            connectTimeoutMs: 60000, // 60 second timeout for initial connection
            defaultQueryTimeoutMs: undefined, // Disable query timeout to prevent premature disconnects
            getMessage: async (key) => {
                logger.debug({ key }, 'Getting message from key');
                return null; // Return null to let Baileys handle message fetching
            },
        });

        // Handle ping/pong to keep connection alive
        sock.ws.on('CB:iq,type:get,xmlns:urn:xmpp:ping', async (node) => {
            logger.debug('Received ping from WhatsApp server, sending pong');
            // Send pong response
            await sock.query({
                tag: 'iq',
                attrs: {
                    to: '@s.whatsapp.net',
                    type: 'result',
                    id: node.attrs.id
                }
            });
        });

        // Event listener for connection updates
        sock.ev.on('connection.update', (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                logger.info('QR code received, please scan with your phone');
                console.log('\n\n\nQR Code for WhatsApp Web:\n', qr, '\n\n\n');
            }

            if (connection === 'close') {
                const shouldReconnect = (lastDisconnect?.error instanceof Boom)
                    ? lastDisconnect.error.output.statusCode !== DisconnectReason.loggedOut
                    : true;

                logger.warn({
                    error: lastDisconnect?.error,
                    shouldReconnect
                }, 'Connection closed');

                // Clean up the current socket reference
                currentSocket = null;
                isReconnecting = false;

                // Check for specific error types
                const statusCode = (lastDisconnect?.error instanceof Boom) 
                    ? lastDisconnect.error.output.statusCode 
                    : null;

                if (statusCode === DisconnectReason.loggedOut) {
                    logger.fatal('Device logged out. Please delete the auth directory and restart.');
                    process.exit(1);
                } else if (shouldReconnect) {
                    // Use exponential backoff for reconnection
                    const retryDelay = statusCode === 440 ? 10000 : 5000; // 10s for conflict, 5s for others
                    logger.info(`Reconnecting to WhatsApp in ${retryDelay/1000} seconds...`);
                    if (reconnectTimeout) {
                        logger.warn('Reconnect already scheduled, skipping duplicate schedule');
                        return;
                    }

                    reconnectTimeout = setTimeout(async () => {
                        reconnectTimeout = null;
                        try {
                            const newSock = await connectToWhatsApp();
                            // Notify the callback about the new socket
                            if (reconnectCallback && newSock) {
                                reconnectCallback(newSock);
                            }
                        } catch (err) {
                            logger.error({ err }, 'Reconnection failed');
                            isReconnecting = false;
                        }
                    }, retryDelay);
                }
            } else if (connection === 'open') {
                logger.info('Successfully connected to WhatsApp');
                isReconnecting = false;
                if (reconnectTimeout) {
                    clearTimeout(reconnectTimeout);
                    reconnectTimeout = null;
                }
            }
        });

        // Save credentials when they get updated
        sock.ev.on('creds.update', saveCreds);

        // Delegate message processing to the message handler
        sock.ev.on('messages.upsert', (m) => {
            handleMessages(sock, m);
        });

        // Listen for group metadata updates (when added to group, group info changes, etc.)
        sock.ev.on('groups.upsert', async (groups) => {
            for (const group of groups) {
                try {
                    logger.info({
                        groupId: group.id,
                        groupName: group.subject,
                        participantCount: group.participants?.length || 0
                    }, 'Group metadata update received');

                    // Fetch group profile picture
                    const groupProfilePicture = await fetchContactProfilePicture(sock, group.id);

                    const apiClient = require('./apiClient');
                    await apiClient.sendGroupMetadata({
                        groupId: group.id,
                        groupName: group.subject || 'Group',
                        participants: group.participants?.map(p => ({
                            jid: convertLidToPhoneJid(sock, p.id),
                            isAdmin: p.admin === 'admin',
                            isSuperAdmin: p.admin === 'superadmin'
                        })) || [],
                        groupDescription: group.desc || '',
                        groupProfilePictureUrl: groupProfilePicture,
                        createdAt: group.creation ? new Date(group.creation * 1000).toISOString() : null
                    });
                } catch (error) {
                    logger.error({
                        error: error.message,
                        groupId: group.id,
                        stack: error.stack
                    }, 'Error processing group metadata update');
                }
            }
        });

        // Listen for group description updates
        sock.ev.on('groups.update', async (updates) => {
            for (const update of updates) {
                try {
                    logger.debug({
                        groupId: update.id,
                        updateKeys: Object.keys(update)
                    }, 'Group update received');

                    try {
                        const groupMetadata = await sock.groupMetadata(update.id);
                        logger.debug({
                            groupId: update.id,
                            groupName: groupMetadata.subject,
                            participantCount: groupMetadata.participants?.length || 0
                        }, 'Fetched updated group metadata from groups.update');

                        // Fetch group profile picture
                        const groupProfilePicture = await fetchContactProfilePicture(sock, groupMetadata.id);

                        const apiClient = require('./apiClient');
                        await apiClient.sendGroupMetadata({
                            groupId: groupMetadata.id,
                            groupName: groupMetadata.subject || 'Group',
                            participants: groupMetadata.participants?.map(p => ({
                                jid: convertLidToPhoneJid(sock, p.id),
                                isAdmin: p.admin === 'admin',
                                isSuperAdmin: p.admin === 'superadmin'
                            })) || [],
                            groupDescription: groupMetadata.desc || '',
                            groupProfilePictureUrl: groupProfilePicture,
                            createdAt: groupMetadata.creation ? new Date(groupMetadata.creation * 1000).toISOString() : null
                        });
                    } catch (fetchError) {
                        logger.warn({
                            error: fetchError.message,
                            groupId: update.id
                        }, 'Could not fetch updated group metadata from groups.update');
                    }
                } catch (error) {
                    logger.error({
                        error: error.message,
                        stack: error.stack
                    }, 'Error processing groups.update');
                }
            }
        });

        // Listen for message status updates (delivery and read receipts)
        sock.ev.on('messages.update', async (updates) => {
            for (const update of updates) {
                try {
                    const { key, update: statusUpdate } = update;
                    
                    // Log the update for debugging
                    logger.debug({ key, statusUpdate }, 'Message status update received');
                    
                    // Check if this is a status update (delivered/read)
                    if (statusUpdate?.status) {
                        const numericStatus = statusUpdate.status;
                        const messageId = key.id;
                        
                        // Skip status updates for edit and protocol messages
                        // These messages have different IDs than the original message
                        // and we don't store them separately in the database
                        if (editMessageIds.has(messageId)) {
                            logger.debug({ messageId }, 'Skipping status update for edit message');
                            continue;
                        }
                        
                        if (protocolMessageIds.has(messageId)) {
                            logger.debug({ messageId }, 'Skipping status update for protocol message');
                            continue;
                        }
                        
                        // Map WhatsApp numeric status to string status
                        // 0 = ERROR, 1 = PENDING, 2 = SERVER_ACK, 3 = DELIVERY_ACK, 4 = READ, 5 = PLAYED
                        let status;
                        switch (numericStatus) {
                            case 0:
                                status = 'failed';
                                break;
                            case 1:
                            case 2:
                                status = 'sent';
                                break;
                            case 3:
                                status = 'delivered';
                                break;
                            case 4:
                            case 5:
                                status = 'read';
                                break;
                            default:
                                status = 'sent';
                        }
                        
                        logger.debug({ messageId, numericStatus, status }, 'Message status changed');
                        
                        // Send status update to backend
                        const apiClient = require('./apiClient');
                        await apiClient.updateMessageStatus(messageId, status);
                    }
                } catch (error) {
                    logger.error({ error, update }, 'Error processing message status update');
                }
            }
        });

        // Listen for group participant updates
        sock.ev.on('group-participants.update', async (update) => {
            try {
                const { id: groupId, participants, action } = update;
                logger.info({
                    groupId,
                    action,
                    participantCount: participants?.length || 0
                }, 'Group participant update received');

                try {
                    const groupMetadata = await sock.groupMetadata(groupId);
                    logger.debug({
                        groupId,
                        groupName: groupMetadata.subject,
                        participantCount: groupMetadata.participants?.length || 0
                    }, 'Fetched updated group metadata');

                    // Fetch group profile picture
                    const groupProfilePicture = await fetchContactProfilePicture(sock, groupMetadata.id);

                    const apiClient = require('./apiClient');
                    await apiClient.sendGroupMetadata({
                        groupId: groupMetadata.id,
                        groupName: groupMetadata.subject || 'Group',
                        participants: groupMetadata.participants?.map(p => ({
                            jid: convertLidToPhoneJid(sock, p.id),
                            isAdmin: p.admin === 'admin',
                            isSuperAdmin: p.admin === 'superadmin'
                        })) || [],
                        groupDescription: groupMetadata.desc || '',
                        groupProfilePictureUrl: groupProfilePicture,
                        createdAt: groupMetadata.creation ? new Date(groupMetadata.creation * 1000).toISOString() : null
                    });
                } catch (fetchError) {
                    logger.warn({
                        error: fetchError.message,
                        groupId
                    }, 'Could not fetch updated group metadata, skipping update');
                }
            } catch (error) {
                logger.error({
                    error: error.message,
                    stack: error.stack
                }, 'Error processing group participant update');
            }
        });

        // Listen for message receipts (alternative event for read receipts)
        sock.ev.on('message-receipt.update', async (receipts) => {
            for (const receipt of receipts) {
                try {
                    const { key, receipt: receiptInfo } = receipt;
                    
                    logger.debug({ key, receiptInfo }, 'Message receipt update received');
                    
                    if (receiptInfo?.readTimestamp || receiptInfo?.deliveredTimestamp) {
                        const messageId = key.id;
                        const status = receiptInfo.readTimestamp ? 'read' : 'delivered';
                        
                        logger.debug({ messageId, status, receiptInfo }, 'Message receipt status changed');
                        
                        // Send status update to backend
                        const apiClient = require('./apiClient');
                        await apiClient.updateMessageStatus(messageId, status);
                    }
                } catch (error) {
                    logger.error({ error, receipt }, 'Error processing message receipt update');
                }
            }
        });

        // Store the current socket
        currentSocket = sock;
        return sock;
    } catch (error) {
        logger.error({ error }, 'Failed to initialize WhatsApp client');
        isReconnecting = false;
        throw error; // Re-throw to allow retry logic to handle it
    }
}

module.exports = { connectToWhatsApp, setReconnectCallback, addEditMessageId, addProtocolMessageId, fetchContactProfilePicture, fetchContactStatus, convertLidToPhoneJid };
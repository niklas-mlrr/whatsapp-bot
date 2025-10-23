const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers, jidDecode, jidNormalizedUser } = require('@whiskeysockets/baileys');
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
// Store recently sent messages so we can satisfy retry requests from recipients
// Map: messageId -> { message: proto content, expiresAt: number }

function indexContactsLidMapping(sock) {
    try {
        const contacts = sock?.contacts || {};
        let count = 0;
        for (const [jid, info] of Object.entries(contacts)) {
            const lidStr = (typeof info?.lid === 'object') ? (info.lid?.jid || info.lid?.toString?.()) : info?.lid;
            if (lidStr && typeof jid === 'string' && jid.endsWith('@s.whatsapp.net')) {
                recordLidToPhone(lidStr, jid);
                count++;
            }
        }
        if (count) logger.debug({ count }, 'Indexed LID mappings from contacts');
    } catch (err) {
        logger.debug({ err: err.message }, 'Failed to index contacts LID mapping');
    }
}
const messageStore = new Map();

// Map LID JIDs to phone JIDs when we discover them from message events
// key: '123456789@lid' -> value: '491234567890@s.whatsapp.net'
const lidToPhoneMap = new Map();

function recordLidToPhone(lidJid, phoneJid) {
    try {
        if (typeof lidJid !== 'string' || typeof phoneJid !== 'string') return;
        if (!lidJid.endsWith('@lid')) return;
        if (!phoneJid.endsWith('@s.whatsapp.net')) return;
        const existing = lidToPhoneMap.get(lidJid);
        if (existing && existing !== phoneJid) {
            logger.debug({ lidJid, existing, phoneJid }, 'Updating LID to phone JID mapping');
        } else if (!existing) {
            logger.debug({ lidJid, phoneJid }, 'Recording LID to phone JID mapping');
        }
        lidToPhoneMap.set(lidJid, phoneJid);
    } catch (err) {
        logger.debug({ err: err.message, lidJid, phoneJid }, 'Failed to record LID mapping');
    }
}

/**
 * Store a sent message's content for a limited time so Baileys can re-upload on retry
 * @param {string} messageId
 * @param {object} messageContent - The exact object passed to sock.sendMessage()
 * @param {number} ttlMs - Time to keep in cache (default 10 minutes)
 */
function storeSentMessage(messageId, messageContent, ttlMs = 10 * 60 * 1000) {
    try {
        if (!messageId || !messageContent) return;
        const expiresAt = Date.now() + ttlMs;
        messageStore.set(messageId, { message: messageContent, expiresAt });
        logger.debug({ messageId, ttlMs }, 'Stored sent message for retry support');
        setTimeout(() => {
            const entry = messageStore.get(messageId);
            if (entry && entry.expiresAt <= Date.now()) {
                messageStore.delete(messageId);
                logger.debug({ messageId }, 'Expired sent message removed from store');
            }
        }, ttlMs).unref?.();
    } catch (err) {
        logger.debug({ err: err.message, messageId }, 'Failed to store sent message');
    }
}

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

    // Already a phone or group JID
    if (!jid.endsWith('@lid')) return jid;

    // Prefer known mapping from runtime
    const mapped = lidToPhoneMap.get(jid);
    if (mapped) return mapped;

    // Resolve via contacts store (authoritative)
    try {
        const contacts = sock?.contacts || {};
        for (const [contactJid, contactInfo] of Object.entries(contacts)) {
            // Some Baileys versions store lid as string or object
            const lidStr = (typeof contactInfo?.lid === 'object') ? (contactInfo.lid?.jid || contactInfo.lid?.toString?.()) : contactInfo?.lid;
            if (lidStr === jid) {
                logger.debug({ lid: jid, phoneJid: contactJid }, 'Resolved LID to phone JID via contacts');
                return contactJid;
            }
        }
    } catch (error) {
        logger.debug({ jid, error: error.message }, 'Contacts lookup for LID failed');
    }

    // Unknown LID: keep as-is; caller may filter it out until we learn mapping
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
                try {
                    const id = key?.id;
                    if (!id) {
                        logger.debug({ key }, 'getMessage called without id');
                        return null;
                    }
                    const entry = messageStore.get(id);
                    if (entry) {
                        // Refresh TTL on access to increase chance of satisfying retries
                        const ttlMs = Math.max(1, entry.expiresAt - Date.now());
                        if (ttlMs > 0) {
                            messageStore.set(id, { message: entry.message, expiresAt: Date.now() + ttlMs });
                        }
                        logger.debug({ id }, 'getMessage hit: returning stored message');
                        return entry.message;
                    }
                    logger.debug({ id }, 'getMessage miss: no stored message');
                    return null;
                } catch (err) {
                    logger.debug({ err: err.message }, 'getMessage error');
                    return null;
                }
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
        sock.ev.on('connection.update', async (update) => {
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

                // After connect: fetch all groups once and push normalized metadata to backend
                try {
                    // Build initial LID mapping from contacts
                    indexContactsLidMapping(sock);
                    const all = await sock.groupFetchAllParticipating();
                    const apiClient = require('./apiClient');
                    for (const g of Object.values(all || {})) {
                        try {
                            const groupProfilePicture = await fetchContactProfilePicture(sock, g.id);
                            const participants = (g.participants || []).map(p => ({
                                jid: convertLidToPhoneJid(sock, p.id),
                                isAdmin: p.admin === 'admin',
                                isSuperAdmin: p.admin === 'superadmin'
                            })).filter(pp => typeof pp.jid === 'string' && pp.jid.endsWith('@s.whatsapp.net'));

                            await apiClient.sendGroupMetadata({
                                groupId: g.id,
                                groupName: g.subject || 'Group',
                                participants,
                                groupDescription: g.desc || '',
                                groupProfilePictureUrl: groupProfilePicture,
                                createdAt: g.creation ? new Date(g.creation * 1000).toISOString() : null
                            });
                        } catch (err) {
                            logger.warn({ err: err.message, groupId: g.id }, 'Failed to push group metadata on connect');
                        }
                    }
                } catch (err) {
                    logger.warn({ err: err.message }, 'Failed to fetch all groups on connect');
                }
            }
        });

        // Save credentials when they get updated
        sock.ev.on('creds.update', saveCreds);

        // Delegate message processing to the message handler
        sock.ev.on('messages.upsert', (m) => {
            handleMessages(sock, m);
        });

        // Contacts upsert/update: learn LID -> phone mapping proactively
        sock.ev.on('contacts.upsert', (contacts) => {
            try {
                for (const c of contacts || []) {
                    // c.id: phone JID, try to find lid via store
                    if (c?.id && typeof c.id === 'string' && c.id.endsWith('@s.whatsapp.net')) {
                        const store = sock.contacts?.[c.id];
                        const lidStr = (typeof store?.lid === 'object') ? (store.lid?.jid || store.lid?.toString?.()) : store?.lid;
                        if (lidStr) recordLidToPhone(lidStr, c.id);
                    }
                }
            } catch (e) {
                logger.debug({ err: e.message }, 'contacts.upsert handler failed');
            }
        });
        sock.ev.on('contacts.update', (updates) => {
            try {
                for (const u of updates || []) {
                    if (u?.id && typeof u.id === 'string' && u.id.endsWith('@s.whatsapp.net')) {
                        const store = sock.contacts?.[u.id];
                        const lidStr = (typeof store?.lid === 'object') ? (store.lid?.jid || store.lid?.toString?.()) : store?.lid;
                        if (lidStr) recordLidToPhone(lidStr, u.id);
                    }
                }
            } catch (e) {
                logger.debug({ err: e.message }, 'contacts.update handler failed');
            }
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
                        const participants = groupMetadata.participants?.map(p => ({
                            jid: convertLidToPhoneJid(sock, p.id),
                            isAdmin: p.admin === 'admin',
                            isSuperAdmin: p.admin === 'superadmin'
                        })).filter(pp => typeof pp.jid === 'string' && pp.jid.endsWith('@s.whatsapp.net')) || [];

                        await apiClient.sendGroupMetadata({
                            groupId: groupMetadata.id,
                            groupName: groupMetadata.subject || 'Group',
                            participants,
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
                    const participants = groupMetadata.participants?.map(p => ({
                        jid: convertLidToPhoneJid(sock, p.id),
                        isAdmin: p.admin === 'admin',
                        isSuperAdmin: p.admin === 'superadmin'
                    })).filter(pp => typeof pp.jid === 'string' && pp.jid.endsWith('@s.whatsapp.net')) || [];

                    await apiClient.sendGroupMetadata({
                        groupId: groupMetadata.id,
                        groupName: groupMetadata.subject || 'Group',
                        participants,
                        groupDescription: groupMetadata.desc || '',
                        groupProfilePictureUrl: groupProfilePicture,
                        createdAt: groupMetadata.creation ? new Date(groupMetadata.creation * 1000).toISOString() : null
                    });

                    // Retry once after a short delay to catch newly learned contacts mapping
                    setTimeout(async () => {
                        try {
                            // New mapping may have arrived via contacts events
                            const refreshed = await sock.groupMetadata(groupId);
                            const participants2 = refreshed.participants?.map(p => ({
                                jid: convertLidToPhoneJid(sock, p.id),
                                isAdmin: p.admin === 'admin',
                                isSuperAdmin: p.admin === 'superadmin'
                            })).filter(pp => typeof pp.jid === 'string' && pp.jid.endsWith('@s.whatsapp.net')) || [];
                            await apiClient.sendGroupMetadata({
                                groupId: refreshed.id,
                                groupName: refreshed.subject || 'Group',
                                participants: participants2,
                                groupDescription: refreshed.desc || '',
                                groupProfilePictureUrl: groupProfilePicture,
                                createdAt: refreshed.creation ? new Date(refreshed.creation * 1000).toISOString() : null
                            });
                        } catch (err2) {
                            logger.debug({ err: err2.message, groupId }, 'Retry push of group metadata failed');
                        }
                    }, 1500);
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
        throw error;
    }
}

module.exports = { connectToWhatsApp, setReconnectCallback, addEditMessageId, addProtocolMessageId, fetchContactProfilePicture, fetchContactStatus, convertLidToPhoneJid, storeSentMessage, recordLidToPhone };
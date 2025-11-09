import { makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers, jidDecode, jidNormalizedUser } from '@whiskeysockets/baileys';
import { Boom } from '@hapi/boom';
import pino from 'pino';
import config from './config.js';
import { logger } from './logger.js';
import { handleMessages } from './messageHandler.js';
import * as apiClient from './apiClient.js';

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
let conflictRetryCount = 0;
const MAX_CONFLICT_RETRIES = 1; // Allow one retry after conflict

// Track edit message IDs to skip their status updates
const editMessageIds = new Set();
const protocolMessageIds = new Set();
// Store recently sent messages so we can satisfy retry requests from recipients
// Map: messageId -> { message: proto content, expiresAt: number }
const messageStore = new Map();

// Global cache to prevent duplicate group metadata sends
// Map: groupId -> timestamp of last send
const groupMetadataSendCache = new Map();
const GROUP_METADATA_DEDUP_WINDOW_MS = 30000; // 30 seconds deduplication window

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
        // Normalize JID to ensure correct query format
        const primary = jidNormalizedUser(jid);
        const attempts = Array.from(new Set([primary, jid].filter(Boolean)));

        for (const target of attempts) {
            try {
                const statusObj = await sock.fetchStatus(target);
                const raw = typeof statusObj?.status === 'string' ? statusObj.status : null;
                const trimmed = typeof raw === 'string' ? raw.trim() : null;
                logger.info({ jid: target, hasStatus: !!trimmed, length: trimmed ? trimmed.length : 0 }, 'Fetched contact status');
                if (trimmed) {
                    return trimmed;
                }
            } catch (innerErr) {
                logger.info({ jid: target, error: innerErr.message }, 'fetchStatus attempt failed');
            }
        }
        return null;
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
 * Check if group metadata should be sent to backend (deduplication)
 * @param {string} groupId - The group ID
 * @param {object} groupData - Optional group data to check community status
 * @returns {boolean} - True if metadata should be sent
 */
function shouldSendGroupMetadata(groupId, groupData = null) {
    // Filter out community parent groups - they have the same name as their announcement group
    // but we only want to store the actual announcement group
    if (groupData && groupData.isCommunity) {
        logger.debug({ 
            groupId, 
            groupName: groupData.subject,
            reason: 'Community parent group filtered out'
        }, 'Skipping community parent group - only storing announcement groups');
        return false;
    }
    
    // Also filter by group ID pattern - community parent groups often end with @g.us 
    // but have specific patterns. For now, we'll rely on the isCommunity flag.
    
    const now = Date.now();
    const lastSent = groupMetadataSendCache.get(groupId);
    
    if (!lastSent || (now - lastSent) > GROUP_METADATA_DEDUP_WINDOW_MS) {
        groupMetadataSendCache.set(groupId, now);
        logger.debug({ 
            groupId, 
            timestamp: now,
            action: 'ALLOWED' 
        }, 'Group metadata send check passed');
        return true;
    }
    
    logger.debug({ 
        groupId, 
        lastSentAgo: Math.round((now - lastSent) / 1000) + 's',
        dedupWindow: GROUP_METADATA_DEDUP_WINDOW_MS / 1000 + 's',
        action: 'SKIPPED'
    }, 'Skipping group metadata send (recently sent)');
    return false;
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
            // Critical for community groups: handle decryption failures gracefully
            shouldIgnoreJid: () => false, // Don't ignore any JIDs
            retryRequestDelayMs: 250, // Retry failed decryptions quickly
            maxMsgRetryCount: 5, // Allow more retries for community group messages
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
                } else if (statusCode === 440) {
                    // Status 440 = conflict (another instance is connected)
                    if (conflictRetryCount < MAX_CONFLICT_RETRIES) {
                        conflictRetryCount++;
                        logger.warn(`Connection conflict detected (attempt ${conflictRetryCount}/${MAX_CONFLICT_RETRIES + 1}). Another WhatsApp Web instance may be connected.`);
                        logger.warn('Waiting 30 seconds for the other instance to fully disconnect, then retrying...');
                        
                        if (reconnectTimeout) {
                            logger.warn('Reconnect already scheduled, skipping duplicate schedule');
                            return;
                        }
                        
                        reconnectTimeout = setTimeout(async () => {
                            reconnectTimeout = null;
                            try {
                                logger.info('Retrying connection after conflict...');
                                const newSock = await connectToWhatsApp();
                                if (reconnectCallback && newSock) {
                                    reconnectCallback(newSock);
                                }
                            } catch (err) {
                                logger.error({ err }, 'Reconnection after conflict failed');
                            }
                        }, 30000); // 30 second delay
                    } else {
                        logger.error('Connection conflict detected: Another WhatsApp Web instance is already connected.');
                        logger.error('This usually means another receiver instance (e.g., on remote server) is running.');
                        logger.error('Please stop the other instance or use different auth directories for each instance.');
                        logger.error('Maximum conflict retries reached. Not reconnecting automatically to prevent connection loop.');
                        process.exit(1);
                    }
                } else if (shouldReconnect) {
                    // Use exponential backoff for reconnection
                    const retryDelay = 5000; // 5s for other errors
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
                conflictRetryCount = 0; // Reset conflict counter on successful connection
                if (reconnectTimeout) {
                    clearTimeout(reconnectTimeout);
                    reconnectTimeout = null;
                }

                // After connect: fetch all groups once and push normalized metadata to backend
                try {
                    // Build initial LID mapping from contacts
                    indexContactsLidMapping(sock);
                    const all = await sock.groupFetchAllParticipating();
                    for (const g of Object.values(all || {})) {
                        try {
                            const groupProfilePicture = await fetchContactProfilePicture(sock, g.id);
                            
                            // Process participants - prioritize 'jid' field over 'id' field
                            const participants = (g.participants || []).map(p => {
                                let phoneJid = null;
                                
                                if (p.jid && typeof p.jid === 'string' && p.jid.endsWith('@s.whatsapp.net')) {
                                    phoneJid = p.jid;
                                } else if (p.id) {
                                    const converted = convertLidToPhoneJid(sock, p.id);
                                    if (converted && converted.endsWith('@s.whatsapp.net')) {
                                        phoneJid = converted;
                                    }
                                }

                                return phoneJid ? {
                                    jid: phoneJid,
                                    isAdmin: p.admin === 'admin',
                                    isSuperAdmin: p.admin === 'superadmin'
                                } : null;
                            }).filter(p => p !== null);

                            if (shouldSendGroupMetadata(g.id, g)) {
                                logger.info({
                                    groupId: g.id,
                                    groupName: g.subject,
                                    participantCount: participants.length,
                                    source: 'connectToWhatsApp',
                                    isCommunity: g.isCommunity || false
                                }, 'Sending group metadata to backend');
                                
                                await apiClient.sendGroupMetadata({
                                    groupId: g.id,
                                    groupName: g.subject || 'Group',
                                    participants,
                                    groupDescription: g.desc || '',
                                    groupProfilePictureUrl: groupProfilePicture,
                                    createdAt: g.creation ? new Date(g.creation * 1000).toISOString() : null
                                });
                            } else {
                                logger.debug({ groupId: g.id }, 'Skipping duplicate group metadata send on connect');
                            }
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
                        participantCount: group.participants?.length || 0,
                        fullGroupStructure: JSON.stringify(group, null, 2)
                    }, 'Group metadata update received');

                    // Skip community parent groups (isCommunity: true) - only process regular groups and announcement groups
                    if (group.isCommunity === true) {
                        logger.info({
                            groupId: group.id,
                            groupName: group.subject,
                            isCommunity: true
                        }, 'Skipping community parent group - not a chat group');
                        continue;
                    }

                    // Fetch group profile picture
                    const groupProfilePicture = await fetchContactProfilePicture(sock, group.id);

                    if (shouldSendGroupMetadata(group.id, group)) {
                        // Process participants with enhanced logging
                        let rawParticipants = group.participants || [];
                        logger.info({
                            groupId: group.id,
                            rawParticipantCount: rawParticipants.length,
                            sampleParticipant: rawParticipants[0] ? JSON.stringify(rawParticipants[0]) : 'none',
                            isCommunityAnnounce: group.isCommunityAnnounce || false,
                            linkedParent: group.linkedParent || null
                        }, 'Processing participants for community group');

                        // For community announcement groups, try to fetch parent community participants
                        // to see if they have phone numbers
                        if (group.isCommunityAnnounce && group.linkedParent) {
                            try {
                                logger.info({ 
                                    announcementGroupId: group.id,
                                    parentGroupId: group.linkedParent 
                                }, 'Fetching parent community group metadata to check for participant phone numbers');
                                
                                const parentMetadata = await sock.groupMetadata(group.linkedParent);
                                logger.info({
                                    parentGroupId: group.linkedParent,
                                    parentParticipantCount: parentMetadata.participants?.length || 0,
                                    allParentParticipants: JSON.stringify(parentMetadata.participants, null, 2)
                                }, 'Fetched parent community group metadata');
                                
                                // Build a map of LID -> phone JID from parent group
                                const lidToPhoneFromParent = new Map();
                                for (const pp of parentMetadata.participants || []) {
                                    if (pp.id && pp.jid && pp.jid.endsWith('@s.whatsapp.net')) {
                                        lidToPhoneFromParent.set(pp.id, pp.jid);
                                        // Also record in global cache
                                        recordLidToPhone(pp.id, pp.jid);
                                        logger.info({ lid: pp.id, phoneJid: pp.jid }, 'Found phone JID in parent community group');
                                    }
                                }
                                
                                // Enrich announcement group participants with parent group data
                                rawParticipants = rawParticipants.map(p => {
                                    if (!p.jid || p.jid === '') {
                                        const phoneFromParent = lidToPhoneFromParent.get(p.id);
                                        if (phoneFromParent) {
                                            logger.info({ 
                                                lid: p.id, 
                                                phoneJid: phoneFromParent 
                                            }, 'Enriched participant with phone JID from parent community group');
                                            return { ...p, jid: phoneFromParent };
                                        }
                                    }
                                    return p;
                                });
                            } catch (err) {
                                logger.warn({ 
                                    error: err.message,
                                    parentGroupId: group.linkedParent 
                                }, 'Could not fetch parent community group metadata');
                            }
                        }

                        // Try to query contacts store for any additional LID mappings
                        logger.info({
                            groupId: group.id,
                            contactStoreSize: Object.keys(sock?.contacts || {}).length,
                            sampleContacts: Object.entries(sock?.contacts || {}).slice(0, 3).map(([jid, info]) => ({
                                jid,
                                lid: (typeof info?.lid === 'object') ? (info.lid?.jid || info.lid?.toString?.()) : info?.lid,
                                name: info?.name || info?.notify
                            }))
                        }, 'Checking contact store for LID mappings');
                        
                        // Scan contact store for any LIDs that match our unresolved participants
                        const contactLidMap = new Map();
                        try {
                            const contacts = sock?.contacts || {};
                            for (const [contactJid, contactInfo] of Object.entries(contacts)) {
                                const lidStr = (typeof contactInfo?.lid === 'object') 
                                    ? (contactInfo.lid?.jid || contactInfo.lid?.toString?.()) 
                                    : contactInfo?.lid;
                                if (lidStr && contactJid.endsWith('@s.whatsapp.net')) {
                                    contactLidMap.set(lidStr, contactJid);
                                }
                            }
                            logger.info({
                                groupId: group.id,
                                contactLidMapSize: contactLidMap.size,
                                allLids: Array.from(contactLidMap.keys())
                            }, 'Built LID map from contact store');
                        } catch (err) {
                            logger.warn({ error: err.message }, 'Failed to build contact LID map');
                        }
                        
                        // Enrich participants with contact store data
                        rawParticipants = rawParticipants.map(p => {
                            if ((!p.jid || p.jid === '') && p.id) {
                                const phoneFromContacts = contactLidMap.get(p.id);
                                if (phoneFromContacts) {
                                    logger.info({ 
                                        lid: p.id, 
                                        phoneJid: phoneFromContacts 
                                    }, 'Enriched participant with phone JID from contact store');
                                    recordLidToPhone(p.id, phoneFromContacts);
                                    return { ...p, jid: phoneFromContacts };
                                }
                            }
                            return p;
                        });

                        // Process participants
                        const participants = [];
                        const unresolvedLids = [];
                        
                        for (const p of rawParticipants) {
                            // For community groups, participants have both 'id' (@lid) and 'jid' (phone number)
                            // Prioritize 'jid' field if available, otherwise try to convert 'id'
                            let phoneJid = null;
                            
                            if (p.jid && typeof p.jid === 'string' && p.jid.endsWith('@s.whatsapp.net')) {
                                // Direct phone JID available
                                phoneJid = p.jid;
                                logger.debug({ lid: p.id, phoneJid: p.jid }, 'Using direct phone JID from participant.jid');
                            } else if (p.id) {
                                // Try to convert LID to phone JID via cached mapping
                                const converted = convertLidToPhoneJid(sock, p.id);
                                if (converted && converted.endsWith('@s.whatsapp.net')) {
                                    phoneJid = converted;
                                    logger.debug({ lid: p.id, phoneJid: converted }, 'Converted LID to phone JID via cache');
                                } else {
                                    // Cannot resolve this LID yet - WhatsApp doesn't provide phone numbers for all community participants
                                    // We'll learn their phone numbers when they send messages
                                    unresolvedLids.push(p.id);
                                    logger.debug({ 
                                        participantId: p.id, 
                                        participantJid: p.jid
                                    }, 'Cannot resolve LID to phone JID yet - will update when participant sends a message');
                                }
                            }

                            if (phoneJid) {
                                participants.push({
                                    jid: phoneJid,
                                    isAdmin: p.admin === 'admin',
                                    isSuperAdmin: p.admin === 'superadmin'
                                });
                            }
                        }
                        
                        if (unresolvedLids.length > 0) {
                            logger.warn({
                                groupId: group.id,
                                unresolvedCount: unresolvedLids.length,
                                unresolvedLids: unresolvedLids,
                                note: 'WhatsApp API limitation: These LIDs may be system accounts or participants whose phone numbers are hidden. Real participants will be added when they send messages.'
                            }, 'Some community participants could not be resolved');
                            
                            // Log detailed info about each unresolved participant
                            for (const lid of unresolvedLids) {
                                const participant = rawParticipants.find(p => p.id === lid);
                                logger.warn({
                                    lid,
                                    fullParticipantData: JSON.stringify(participant),
                                    isAdmin: participant?.admin || 'none',
                                    hasJid: !!(participant?.jid),
                                    jidValue: participant?.jid || 'empty'
                                }, 'Unresolved participant details');
                            }
                        }

                        logger.info({
                            groupId: group.id,
                            groupName: group.subject,
                            rawParticipantCount: rawParticipants.length,
                            resolvedParticipantCount: participants.length,
                            skippedCount: rawParticipants.length - participants.length,
                            source: 'groups.upsert',
                            isCommunity: group.isCommunity || false,
                            isCommunityAnnounce: group.isCommunityAnnounce || false,
                            groupType: group.type || 'unknown',
                            parentGroup: group.linkedParent || null
                        }, 'Sending group metadata to backend');
                        
                        await apiClient.sendGroupMetadata({
                            groupId: group.id,
                            groupName: group.subject || 'Group',
                            participants,
                            groupDescription: group.desc || '',
                            groupProfilePictureUrl: groupProfilePicture,
                            createdAt: group.creation ? new Date(group.creation * 1000).toISOString() : null
                        });
                    } else {
                        logger.debug({ groupId: group.id }, 'Skipping duplicate group metadata send in groups.upsert');
                    }
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

                        // Skip community parent groups (isCommunity: true) - only process regular groups and announcement groups
                        if (groupMetadata.isCommunity === true) {
                            logger.info({
                                groupId: groupMetadata.id,
                                groupName: groupMetadata.subject,
                                isCommunity: true
                            }, 'Skipping community parent group update - not a chat group');
                            continue;
                        }

                        // Fetch group profile picture
                        const groupProfilePicture = await fetchContactProfilePicture(sock, groupMetadata.id);

                        // Process participants - prioritize 'jid' field over 'id' field
                        const participants = (groupMetadata.participants || []).map(p => {
                            let phoneJid = null;
                            
                            if (p.jid && typeof p.jid === 'string' && p.jid.endsWith('@s.whatsapp.net')) {
                                phoneJid = p.jid;
                            } else if (p.id) {
                                const converted = convertLidToPhoneJid(sock, p.id);
                                if (converted && converted.endsWith('@s.whatsapp.net')) {
                                    phoneJid = converted;
                                }
                            }

                            return phoneJid ? {
                                jid: phoneJid,
                                isAdmin: p.admin === 'admin',
                                isSuperAdmin: p.admin === 'superadmin'
                            } : null;
                        }).filter(p => p !== null);

                        if (shouldSendGroupMetadata(groupMetadata.id, groupMetadata)) {
                            await apiClient.sendGroupMetadata({
                                groupId: groupMetadata.id,
                                groupName: groupMetadata.subject || 'Group',
                                participants,
                                groupDescription: groupMetadata.desc || '',
                                groupProfilePictureUrl: groupProfilePicture,
                                createdAt: groupMetadata.creation ? new Date(groupMetadata.creation * 1000).toISOString() : null
                            });
                        } else {
                            logger.debug({ groupId: groupMetadata.id }, 'Skipping duplicate group metadata send in groups.update');
                        }
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
            logger.info({ 
                updateCount: updates.length,
                timestamp: new Date().toISOString()
            }, '=== Message status updates batch received from WhatsApp ===');
            
            for (const update of updates) {
                try {
                    const { key, update: statusUpdate } = update;
                    
                    // Log the update for debugging
                    logger.info({ 
                        key, 
                        statusUpdate, 
                        messageId: key.id,
                        remoteJid: key.remoteJid,
                        fromMe: key.fromMe,
                        participant: key.participant,
                        timestamp: new Date().toISOString()
                    }, 'Message status update received from WhatsApp');
                    
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
                        
                        logger.info({ 
                            messageId, 
                            numericStatus, 
                            status,
                            remoteJid: key.remoteJid,
                            fromMe: key.fromMe,
                            participant: key.participant,
                            timestamp: new Date().toISOString()
                        }, 'Message status changed - sending to backend');
                        
                        // Send status update to backend
                        const result = await apiClient.updateMessageStatus(messageId, status);
                        
                        logger.info({
                            messageId,
                            status,
                            result: result ? 'success' : 'failed',
                            timestamp: new Date().toISOString()
                        }, 'Message status update completed');
                    } else {
                        logger.debug({ 
                            update,
                            messageId: key.id,
                            reason: 'No status field in update'
                        }, 'Skipping message update without status');
                    }
                } catch (error) {
                    logger.error({ 
                        error, 
                        update,
                        timestamp: new Date().toISOString()
                    }, 'Error processing message status update');
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

                    // Skip community parent groups (isCommunity: true) - only process regular groups and announcement groups
                    if (groupMetadata.isCommunity === true) {
                        logger.info({
                            groupId: groupMetadata.id,
                            groupName: groupMetadata.subject,
                            isCommunity: true
                        }, 'Skipping community parent group participant update - not a chat group');
                        return;
                    }

                    // Fetch group profile picture
                    const groupProfilePicture = await fetchContactProfilePicture(sock, groupMetadata.id);

                    // Process participants - prioritize 'jid' field over 'id' field
                    const participants = (groupMetadata.participants || []).map(p => {
                        let phoneJid = null;
                        
                        if (p.jid && typeof p.jid === 'string' && p.jid.endsWith('@s.whatsapp.net')) {
                            phoneJid = p.jid;
                        } else if (p.id) {
                            const converted = convertLidToPhoneJid(sock, p.id);
                            if (converted && converted.endsWith('@s.whatsapp.net')) {
                                phoneJid = converted;
                            }
                        }

                        return phoneJid ? {
                            jid: phoneJid,
                            isAdmin: p.admin === 'admin',
                            isSuperAdmin: p.admin === 'superadmin'
                        } : null;
                    }).filter(p => p !== null);

                    if (shouldSendGroupMetadata(groupMetadata.id, groupMetadata)) {
                        await apiClient.sendGroupMetadata({
                            groupId: groupMetadata.id,
                            groupName: groupMetadata.subject || 'Group',
                            participants,
                            groupDescription: groupMetadata.desc || '',
                            groupProfilePictureUrl: groupProfilePicture,
                            createdAt: groupMetadata.creation ? new Date(groupMetadata.creation * 1000).toISOString() : null
                        });
                    } else {
                        logger.debug({ groupId: groupMetadata.id }, 'Skipping duplicate group metadata send in group-participants.update');
                    }

                    // Retry once after a short delay to catch newly learned contacts mapping
                    setTimeout(async () => {
                        try {
                            // New mapping may have arrived via contacts events
                            const refreshed = await sock.groupMetadata(groupId);
                            // Process participants - prioritize 'jid' field over 'id' field
                            const participants2 = (refreshed.participants || []).map(p => {
                                let phoneJid = null;
                                
                                if (p.jid && typeof p.jid === 'string' && p.jid.endsWith('@s.whatsapp.net')) {
                                    phoneJid = p.jid;
                                } else if (p.id) {
                                    const converted = convertLidToPhoneJid(sock, p.id);
                                    if (converted && converted.endsWith('@s.whatsapp.net')) {
                                        phoneJid = converted;
                                    }
                                }

                                return phoneJid ? {
                                    jid: phoneJid,
                                    isAdmin: p.admin === 'admin',
                                    isSuperAdmin: p.admin === 'superadmin'
                                } : null;
                            }).filter(p => p !== null);
                            
                            // Bypass deduplication for retry since we want to update with new participant mappings
                            if (shouldSendGroupMetadata(refreshed.id, refreshed)) {
                                await apiClient.sendGroupMetadata({
                                    groupId: refreshed.id,
                                    groupName: refreshed.subject || 'Group',
                                    participants: participants2,
                                    groupDescription: refreshed.desc || '',
                                    groupProfilePictureUrl: groupProfilePicture,
                                    createdAt: refreshed.creation ? new Date(refreshed.creation * 1000).toISOString() : null
                                });
                            } else {
                                logger.debug({ groupId: refreshed.id }, 'Skipping retry group metadata send (recently sent)');
                            }
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

export { connectToWhatsApp, setReconnectCallback, addEditMessageId, addProtocolMessageId, fetchContactProfilePicture, fetchContactStatus, convertLidToPhoneJid, storeSentMessage, recordLidToPhone, shouldSendGroupMetadata };
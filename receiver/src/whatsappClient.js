const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers } = require('@whiskeysockets/baileys');
const { Boom } = require('@hapi/boom');
const pino = require('pino');
const config = require('./config');
const { logger } = require('./logger');
const { handleMessages } = require('./messageHandler');

let isReconnecting = false;
let currentSocket = null;
let reconnectCallback = null;

/**
 * Set a callback to be called when the socket reconnects
 * @param {Function} callback - Function to call with the new socket instance
 */
function setReconnectCallback(callback) {
    reconnectCallback = callback;
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
                    setTimeout(async () => {
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
            }
        });

        // Save credentials when they get updated
        sock.ev.on('creds.update', saveCreds);

        // Delegate message processing to the message handler
        sock.ev.on('messages.upsert', (m) => {
            handleMessages(sock, m);
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

module.exports = { connectToWhatsApp, setReconnectCallback };
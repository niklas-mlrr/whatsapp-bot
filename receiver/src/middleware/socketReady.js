/**
 * Socket state management for WhatsApp connection
 */

/**
 * @typedef {Object} SocketState
 * @property {object|null} sockInstance - The current WhatsApp socket instance
 * @property {boolean} isConnected - Whether the socket is connected
 * @property {boolean} awaitingInitialSync - Whether initial sync is pending
 */

// Socket state singleton
let sockInstance = null;
let isConnected = false;
let awaitingInitialSync = true;

/**
 * Gets the current socket instance.
 * @returns {object|null}
 */
export function getSocketInstance() {
    return sockInstance;
}

/**
 * Checks if the socket is connected.
 * @returns {boolean}
 */
export function isSocketConnected() {
    return isConnected;
}

/**
 * Checks if initial sync is pending.
 * @returns {boolean}
 */
export function isAwaitingSync() {
    return awaitingInitialSync;
}

/**
 * Sets the socket instance and sets up connection update listeners.
 * @param {object} sock - The WhatsApp socket instance
 * @returns {void}
 */
export function setSocketInstance(sock) {
    sockInstance = sock;

    // Check if socket is already connected
    if (sock && sock.user) {
        isConnected = true;
        awaitingInitialSync = false;
        console.log('Socket already connected and ready.');
    }

    // Listen for connection updates
    if (sock?.ev && sock.ev.on) {
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

/**
 * Waits for the socket to be ready (connected and synced).
 * @param {number} timeoutMs - Timeout in milliseconds (default: 10000)
 * @returns {Promise<void>}
 * @throws {Error} If socket not initialized or timeout reached
 */
export async function waitForSocketReady(timeoutMs = 10000) {
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

/**
 * Resets the socket state (for reconnection).
 * @returns {void}
 */
export function resetSocketState() {
    sockInstance = null;
    isConnected = false;
    awaitingInitialSync = true;
}

export default {
    getSocketInstance,
    isSocketConnected,
    isAwaitingSync,
    setSocketInstance,
    waitForSocketReady,
    resetSocketState
};
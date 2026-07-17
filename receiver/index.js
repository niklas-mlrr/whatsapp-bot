/**
 * WhatsApp Receiver - Main Entry Point
 *
 * This is the main entry point for the WhatsApp receiver service.
 * It handles WhatsApp connection management and starts the Express server.
 */

import { connectToWhatsApp, setReconnectCallback } from './src/whatsappClient.js';
import { createApp } from './src/app.js';
import { setSocketInstance, isSocketConnected } from './src/middleware/socketReady.js';
import { pollMessagesStore, pollUpdatesStore } from './src/stores.js';

// Export stores for use in other modules (for poll message handling)
export { pollMessagesStore, pollUpdatesStore };

/**
 * Starts the WhatsApp receiver service.
 */
async function start() {
    // Set up the reconnect callback before initial connection
    setReconnectCallback((newSock) => {
        setSocketInstance(newSock);
    });

    // Connect to WhatsApp
    const sock = await connectToWhatsApp();
    setSocketInstance(sock);

    if (!sock) {
        console.log('WhatsApp connection is currently locked/unavailable. Server will continue running.');
    }

    // Create Express app with all routes
    const app = createApp({
        pollMessagesStore,
        pollUpdatesStore
    });

    // Start the server
    const PORT = process.env.PORT || 3000;
    const HOST = process.env.HOST || '127.0.0.1';
    app.listen(PORT, HOST, () => {
        console.log(`Express server listening on ${HOST}:${PORT}`);
    });
}

// Start the application
start().catch(err => {
    console.error("Unhandled Error during initial connectToWhatsApp: ", err);
    process.exit(1);
});
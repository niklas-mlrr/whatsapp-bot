/**
 * Express application factory
 * Creates and configures the Express app with all routes and middleware.
 */

import express from 'express';
import bodyParser from 'body-parser';
import { mountRoutes } from './routes/index.js';

/**
 * Creates an Express application with all routes and middleware configured.
 *
 * @param {Object} options - Configuration options
 * @param {Map} options.pollMessagesStore - Poll messages store
 * @param {Map} options.pollUpdatesStore - Poll updates store
 * @returns {import('express').Application} Configured Express app
 */
export function createApp(options = {}) {
    const { pollMessagesStore, pollUpdatesStore } = options;

    const app = express();

    // Parse JSON bodies with increased limit for media
    // Must cover base64-encoded media incl. overhead (4/3) for files up to
    // Laravel's 50 MB upload limit -> ~67 MB, plus headroom.
    app.use(bodyParser.json({ limit: '75mb' }));

    // Mount all routes
    mountRoutes(app, {
        pollMessagesStore,
        pollUpdatesStore
    });

    return app;
}

export default { createApp };
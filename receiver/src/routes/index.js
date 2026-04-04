/**
 * Route aggregator - mounts all route modules
 */

import { createStatusRoutes } from './status.js';
import { createMessageRoutes } from './messages.js';
import { createReactionRoutes } from './reactions.js';
import { createEditRoutes } from './edits.js';
import { createPollRoutes } from './polls.js';

/**
 * Mounts all routes on the Express app.
 *
 * @param {import('express').Application} app - Express app instance
 * @param {Object} stores - Shared stores
 * @param {Map} stores.pollMessagesStore - Poll messages store
 * @param {Map} stores.pollUpdatesStore - Poll updates store
 */
export function mountRoutes(app, stores) {
    const { pollMessagesStore, pollUpdatesStore } = stores;

    // Status and health check routes (no auth required)
    createStatusRoutes(app, pollMessagesStore, pollUpdatesStore);

    // Message routes (auth required, handled in route handler)
    createMessageRoutes(app, pollMessagesStore);

    // Reaction routes (no auth required)
    createReactionRoutes(app);

    // Edit and delete routes (auth required)
    createEditRoutes(app);

    // Poll routes (auth required)
    createPollRoutes(app, pollMessagesStore, pollUpdatesStore);
}

export default { mountRoutes };
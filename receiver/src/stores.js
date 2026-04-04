/**
 * Shared stores for WhatsApp message handling
 * This module provides singleton stores for poll messages and updates.
 * Importing from this module avoids circular dependencies with index.js.
 */

// Store poll messages and their updates for vote aggregation
const pollMessagesStore = new Map(); // pollMessageId -> poll message
const pollUpdatesStore = new Map(); // pollMessageId -> array of poll updates

export {
    pollMessagesStore,
    pollUpdatesStore
};
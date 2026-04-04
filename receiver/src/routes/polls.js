/**
 * Poll voting routes
 */

import express from 'express';
import { verifyApiKey } from '../middleware/auth.js';
import { getSocketInstance, isSocketConnected, waitForSocketReady } from '../middleware/socketReady.js';
import { resolveChatJid } from '../utils/jid.js';
import { getAggregateVotesInPollMessage } from '@whiskeysockets/baileys';

const router = express.Router();

/**
 * @param {import('express').Application} app - Express app instance
 * @param {Map} pollMessagesStore - Poll messages store (from index.js)
 * @param {Map} pollUpdatesStore - Poll updates store (from index.js)
 */
export function createPollRoutes(app, pollMessagesStore, pollUpdatesStore) {
    // Send poll vote endpoint
    app.post('/send-poll-vote', verifyApiKey, async (req, res) => {
        console.log('Received send-poll-vote request:', req.body);

        const sockInstance = getSocketInstance();
        if (!sockInstance || !isSocketConnected()) {
            console.error('WhatsApp not connected');
            return res.status(503).json({ error: 'WhatsApp not connected' });
        }

        const { chatJid, pollMessageId, selectedOptions } = req.body;

        if (!chatJid || !pollMessageId || !Array.isArray(selectedOptions)) {
            console.error('Missing required fields:', { chatJid, pollMessageId, selectedOptions });
            return res.status(400).json({ error: 'Missing chatJid, pollMessageId, or selectedOptions (must be array)' });
        }

        try {
            await waitForSocketReady(20000);
        } catch (syncError) {
            console.error('WhatsApp socket not ready:', syncError.message);
            return res.status(503).json({ error: 'WhatsApp initial sync incomplete', details: syncError.message });
        }

        try {
            // Ensure chat JID has proper WhatsApp format
            const formattedChatJid = resolveChatJid(chatJid);

            console.log('Sending poll vote to WhatsApp:', {
                chatJid: formattedChatJid,
                pollMessageId,
                selectedOptions
            });

            // Use Baileys' sendMessage with poll vote format
            const pollVoteMessage = {
                pollUpdateMessage: {
                    pollCreationMessageKey: {
                        remoteJid: formattedChatJid,
                        fromMe: false,
                        id: pollMessageId
                    },
                    vote: selectedOptions.map(optionIndex => Buffer.from([optionIndex])),
                    senderTimestampMs: Date.now()
                }
            };

            // Send using relayMessage with proper participant info
            await sockInstance.relayMessage(formattedChatJid, pollVoteMessage, {});

            console.log('Poll vote sent successfully');
            res.json({
                status: 'sent',
                messageId: pollMessageId
            });

        } catch (err) {
            console.error('Failed to send poll vote:', {
                error: err.message,
                stack: err.stack,
                chatJid,
                pollMessageId,
                selectedOptions
            });
            res.status(500).json({
                error: 'Failed to send poll vote',
                details: err.message
            });
        }
    });

    // Get poll votes endpoint
    app.post('/get-poll-votes', verifyApiKey, async (req, res) => {
        console.log('Received get-poll-votes request:', req.body);

        const sockInstance = getSocketInstance();
        if (!sockInstance || !isSocketConnected()) {
            console.error('WhatsApp not connected');
            return res.status(503).json({ error: 'WhatsApp not connected' });
        }

        const { chatJid, pollMessageId } = req.body;

        if (!chatJid || !pollMessageId) {
            console.error('Missing required fields:', { chatJid, pollMessageId });
            return res.status(400).json({ error: 'Missing chatJid or pollMessageId' });
        }

        try {
            await waitForSocketReady(20000);
        } catch (syncError) {
            console.error('WhatsApp socket not ready:', syncError.message);
            return res.status(503).json({ error: 'WhatsApp initial sync incomplete', details: syncError.message });
        }

        try {
            // Ensure chat JID has proper WhatsApp format
            const formattedChatJid = resolveChatJid(chatJid);

            console.log('Fetching poll votes from WhatsApp:', {
                chatJid: formattedChatJid,
                pollMessageId
            });

            // Get the poll message and updates from our store
            const pollMessage = pollMessagesStore.get(pollMessageId);
            const pollUpdates = pollUpdatesStore.get(pollMessageId) || [];

            console.log('Poll data from store:', {
                hasPollMessage: !!pollMessage,
                updateCount: pollUpdates.length
            });

            if (!pollMessage) {
                console.log('Poll message not found in store');
                return res.json({
                    status: 'success',
                    votes: [],
                    message: 'Poll message not found in cache'
                });
            }

            try {
                // Use getAggregateVotesInPollMessage to get vote counts
                const votes = await getAggregateVotesInPollMessage({
                    message: pollMessage,
                    pollUpdates: pollUpdates
                });

                console.log('Poll votes aggregated:', {
                    voteCount: votes?.length || 0,
                    votes: votes
                });

                res.json({
                    status: 'success',
                    votes: votes || []
                });
            } catch (voteErr) {
                console.log('Failed to aggregate poll votes:', voteErr.message, voteErr.stack);

                // Return empty votes array
                res.json({
                    status: 'success',
                    votes: []
                });
            }

        } catch (err) {
            console.error('Failed to fetch poll votes:', {
                error: err.message,
                stack: err.stack,
                chatJid,
                pollMessageId
            });
            res.status(500).json({
                error: 'Failed to fetch poll votes',
                details: err.message
            });
        }
    });

    // Leave WhatsApp group endpoint
    app.post('/leave-group', verifyApiKey, async (req, res) => {
        try {
            const sockInstance = getSocketInstance();
            if (!sockInstance || !isSocketConnected()) {
                return res.status(503).json({ error: 'WhatsApp not connected' });
            }

            const { groupJid } = req.body || {};
            if (!groupJid || typeof groupJid !== 'string') {
                return res.status(400).json({ error: 'Missing groupJid' });
            }

            try {
                await waitForSocketReady(20000);
            } catch (e) {
                return res.status(503).json({ error: 'WhatsApp initial sync incomplete', details: e.message });
            }

            const jid = groupJid.endsWith('@g.us') ? groupJid : groupJid;

            try {
                await sockInstance.groupLeave(jid);
                return res.json({ status: 'left' });
            } catch (err) {
                return res.status(500).json({ error: 'Failed to leave group', details: err.message });
            }
        } catch (err) {
            return res.status(500).json({ error: 'Unexpected error', details: err.message });
        }
    });
}

export default { createPollRoutes };
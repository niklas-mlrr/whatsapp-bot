/**
 * Reaction routes
 */

import express from 'express';
import { getSocketInstance, isSocketConnected } from '../middleware/socketReady.js';
import { resolveChatJid } from '../utils/jid.js';

const router = express.Router();

/**
 * @param {import('express').Application} app - Express app instance
 */
export function createReactionRoutes(app) {
    app.post('/send-reaction', async (req, res) => {
        console.log('Received send-reaction request:', req.body);

        const sockInstance = getSocketInstance();
        if (!sockInstance || !isSocketConnected()) {
            console.error('WhatsApp not connected');
            return res.status(503).json({ error: 'WhatsApp not connected' });
        }

        const { chat, messageId, emoji, fromMe } = req.body;

        if (!chat || !messageId) {
            console.error('Missing required fields:', { chat, messageId });
            return res.status(400).json({ error: 'Missing chat or messageId' });
        }

        try {
            // Normalize chat JID
            let chatJid;
            if (typeof chat === 'string' && chat.includes('@')) {
                chatJid = chat; // already a JID
            } else {
                const number = String(chat || '').replace(/^\+/, '');
                chatJid = `${number}@s.whatsapp.net`;
            }

            // Optional participant for group reactions
            const { participant } = req.body || {};

            // WhatsApp requires key.participant for group reactions to messages not from us
            if (chatJid.endsWith('@g.us') && !(fromMe === true || fromMe === 'true')) {
                if (!(typeof participant === 'string' && participant.includes('@'))) {
                    console.error('Missing participant for group reaction to non-self message', { chatJid, messageId, fromMe, participant });
                    return res.status(400).json({ error: 'Missing participant for group reaction' });
                }
            }

            // Build reaction payload for Baileys
            const reactionMessage = {
                react: {
                    text: emoji || '', // Empty string removes the reaction
                    key: {
                        remoteJid: chatJid,
                        id: messageId,
                        fromMe: fromMe === true || fromMe === 'true'
                    }
                }
            };

            // If this is a group chat and a participant was provided, include it
            if (typeof participant === 'string' && participant.includes('@') && chatJid.endsWith('@g.us')) {
                reactionMessage.react.key.participant = participant;
            }

            console.log('Sending reaction to WhatsApp:', reactionMessage);
            await sockInstance.sendMessage(chatJid, reactionMessage);

            console.log('Reaction sent successfully');
            res.json({ status: 'sent' });

        } catch (err) {
            console.error('Failed to send reaction:', {
                error: err.message,
                stack: err.stack,
                chat,
                messageId,
                emoji
            });
            res.status(500).json({
                error: 'Failed to send reaction',
                details: err.message
            });
        }
    });
}

export default { createReactionRoutes };
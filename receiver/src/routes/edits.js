/**
 * Message edit and delete routes
 */

import express from 'express';
import { verifyApiKey } from '../middleware/auth.js';
import { getSocketInstance, isSocketConnected, waitForSocketReady } from '../middleware/socketReady.js';
import { resolveChatJid } from '../utils/jid.js';
import * as whatsappClient from '../whatsappClient.js';

const router = express.Router();

/**
 * @param {import('express').Application} app - Express app instance
 */
export function createEditRoutes(app) {
    // Delete message endpoint
    app.post('/delete-message', verifyApiKey, async (req, res) => {
        console.log('Received delete-message request:', req.body);

        const sockInstance = getSocketInstance();
        if (!sockInstance || !isSocketConnected()) {
            console.error('WhatsApp not connected');
            return res.status(503).json({ error: 'WhatsApp not connected' });
        }

        const { messageId, chatJid, forEveryone } = req.body;

        if (!messageId || !chatJid) {
            console.error('Missing required fields:', { messageId, chatJid });
            return res.status(400).json({ error: 'Missing messageId or chatJid' });
        }

        try {
            // Ensure chat JID has proper WhatsApp format
            const formattedChatJid = resolveChatJid(chatJid);

            // Delete message using Baileys sendMessage with delete key
            const deletePayload = {
                delete: {
                    remoteJid: formattedChatJid,
                    fromMe: true,
                    id: messageId
                }
            };

            console.log('Deleting message on WhatsApp:', { formattedChatJid, deletePayload });
            const result = await sockInstance.sendMessage(formattedChatJid, deletePayload);

            // Track the new delete message ID to ignore its status updates
            if (result?.key?.id) {
                whatsappClient.addEditMessageId(result.key.id);
                console.log('Tracking delete message ID:', result.key.id);
            }

            console.log('Message deleted successfully');
            res.json({ status: 'deleted' });

        } catch (err) {
            console.error('Failed to delete message:', {
                error: err.message,
                stack: err.stack,
                messageId,
                chatJid
            });
            res.status(500).json({
                error: 'Failed to delete message',
                details: err.message
            });
        }
    });

    // Edit message endpoint
    app.post('/edit-message', verifyApiKey, async (req, res) => {
        console.log('Received edit-message request:', req.body);

        const sockInstance = getSocketInstance();
        if (!sockInstance || !isSocketConnected()) {
            console.error('WhatsApp not connected');
            return res.status(503).json({ error: 'WhatsApp not connected' });
        }

        const { messageId, chatJid, newContent } = req.body;

        if (!messageId || !chatJid || !newContent) {
            console.error('Missing required fields:', { messageId, chatJid, newContent });
            return res.status(400).json({ error: 'Missing messageId, chatJid, or newContent' });
        }

        try {
            // Ensure chat JID has proper WhatsApp format
            const formattedChatJid = resolveChatJid(chatJid);

            // Edit message using Baileys protocol
            const editPayload = {
                text: newContent,
                edit: {
                    remoteJid: formattedChatJid,
                    fromMe: true,
                    id: messageId
                }
            };

            console.log('Editing message on WhatsApp:', { formattedChatJid, editPayload });
            const result = await sockInstance.sendMessage(formattedChatJid, editPayload);

            // Track the new edit message ID to ignore its status updates
            if (result?.key?.id) {
                whatsappClient.addEditMessageId(result.key.id);
                console.log('Tracking edit message ID:', result.key.id);
            }

            console.log('Message edited successfully');
            res.json({ status: 'edited' });

        } catch (err) {
            console.error('Failed to edit message:', {
                error: err.message,
                stack: err.stack,
                messageId,
                chatJid,
                newContent
            });
            res.status(500).json({
                error: 'Failed to edit message',
                details: err.message
            });
        }
    });
}

export default { createEditRoutes };
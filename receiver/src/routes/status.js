/**
 * Status and health check routes
 */

import * as whatsappClient from '../whatsappClient.js';
import { getSocketInstance, isSocketConnected, setSocketInstance, isAwaitingSync } from '../middleware/socketReady.js';

/**
 * @param {import('express').Application} app - Express app instance
 * @param {Map} pollMessagesStore - Poll messages store (from index.js)
 * @param {Map} pollUpdatesStore - Poll updates store (from index.js)
 */
export function createStatusRoutes(app, pollMessagesStore, pollUpdatesStore) {
    // Health check endpoint
    app.get('/status', (req, res) => {
        const sockInstance = getSocketInstance();
        const lock = whatsappClient.getConnectionLock?.();
        const failureReportPath = whatsappClient.getLastFailureReportPath?.();

        res.json({
            status: 'running',
            whatsapp: {
                initialized: !!sockInstance,
                connected: isSocketConnected(),
                locked: !!lock,
                lock: lock ? {
                    lockedAt: lock.lockedAt,
                    reason: lock.reason,
                    statusCode: lock?.details?.statusCode,
                    deviceRemoved: lock?.details?.deviceRemoved,
                } : null,
                failureReportPath: failureReportPath || null,
                user: sockInstance?.user ? {
                    id: sockInstance.user.id,
                    name: sockInstance.user.name
                } : null
            }
        });
    });

    // Manual recovery endpoint: clears connection lock and performs a single reconnect attempt.
    // This does NOT re-enable auto-reconnect loops.
    app.post('/whatsapp/retry', async (req, res) => {
        try {
            whatsappClient.clearConnectionLock?.();
            const newSock = await whatsappClient.connectToWhatsApp();
            if (newSock) {
                setSocketInstance(newSock);
            }

            const sockInstance = getSocketInstance();
            const lock = whatsappClient.getConnectionLock?.();
            const failureReportPath = whatsappClient.getLastFailureReportPath?.();

            res.json({
                ok: true,
                whatsapp: {
                    initialized: !!sockInstance,
                    connected: isSocketConnected(),
                    locked: !!lock,
                    lock: lock ? {
                        lockedAt: lock.lockedAt,
                        reason: lock.reason,
                        statusCode: lock?.details?.statusCode,
                        deviceRemoved: lock?.details?.deviceRemoved,
                    } : null,
                    failureReportPath: failureReportPath || null,
                    user: sockInstance?.user ? {
                        id: sockInstance.user.id,
                        name: sockInstance.user.name,
                    } : null,
                },
            });
        } catch (error) {
            res.status(500).json({ ok: false, error: error?.message || 'retry_failed' });
        }
    });
}

export default { createStatusRoutes };
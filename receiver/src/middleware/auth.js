/**
 * Authentication middleware for API endpoints
 */
import crypto from 'crypto';

/**
 * Constant-time string comparison to prevent timing attacks.
 * Uses crypto.timingSafeEqual with Buffer comparison.
 *
 * @param {string} a - First string
 * @param {string} b - Second string
 * @returns {boolean} - True if strings match
 */
function timingSafeEqual(a, b) {
    if (typeof a !== 'string' || typeof b !== 'string') {
        return false;
    }
    // Use same-length comparison by comparing lengths first (lengths are not secret)
    if (a.length !== b.length) {
        return false;
    }
    return crypto.timingSafeEqual(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8'));
}

/**
 * Middleware to verify API key for protected endpoints.
 * Always requires RECEIVER_API_KEY to be set.
 *
 * @param {import('express').Request} req
 * @param {import('express').Response} res
 * @param {import('express').NextFunction} next
 */
export function verifyApiKey(req, res, next) {
    const apiKey = process.env.RECEIVER_API_KEY;

    if (!apiKey) {
        console.error('SECURITY ERROR: RECEIVER_API_KEY not set in environment');
        return res.status(503).json({ error: 'Service unavailable: API key not configured' });
    }

    const providedKey = req.headers['x-api-key'] || req.headers['authorization'];

    if (!providedKey) {
        console.warn('Missing API key in request', {
            ip: req.ip,
            userAgent: req.headers['user-agent'],
            path: req.path
        });
        return res.status(401).json({ error: 'Unauthorized: API key required' });
    }

    // Remove 'Bearer ' prefix if present
    let cleanKey = providedKey;
    if (cleanKey && cleanKey.startsWith('Bearer ')) {
        cleanKey = cleanKey.substring(7);
    }

    // Verify API key using constant-time comparison
    if (!timingSafeEqual(cleanKey, apiKey)) {
        console.warn('Unauthorized access attempt to protected endpoint', {
            ip: req.ip,
            userAgent: req.headers['user-agent'],
            path: req.path
        });
        return res.status(401).json({ error: 'Unauthorized: Invalid API key' });
    }

    next();
}

export default {
    verifyApiKey
};
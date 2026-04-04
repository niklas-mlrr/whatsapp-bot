/**
 * Authentication middleware for API endpoints
 */

/**
 * Middleware to verify API key for protected endpoints.
 * Blocks requests in production if API key is not configured.
 * In development, logs a warning but allows the request through.
 *
 * @param {import('express').Request} req
 * @param {import('express').Response} res
 * @param {import('express').NextFunction} next
 */
export function verifyApiKey(req, res, next) {
    const apiKey = process.env.RECEIVER_API_KEY;

    if (!apiKey) {
        console.warn('SECURITY WARNING: RECEIVER_API_KEY not set in environment');
        // In production, block the request
        if (process.env.NODE_ENV === 'production') {
            return res.status(503).json({ error: 'Service unavailable: API key not configured' });
        }
    }

    const providedKey = req.headers['x-api-key'] || req.headers['authorization'];

    // Remove 'Bearer ' prefix if present
    let cleanKey = providedKey;
    if (cleanKey && cleanKey.startsWith('Bearer ')) {
        cleanKey = cleanKey.substring(7);
    }

    // Verify API key using constant-time comparison
    if (apiKey && cleanKey !== apiKey) {
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
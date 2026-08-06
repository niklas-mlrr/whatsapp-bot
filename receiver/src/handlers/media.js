/**
 * Media handling utilities for WhatsApp messages
 */

import axios from 'axios';
import fs from 'fs';
import { URL } from 'url';

/**
 * Blocked IP ranges for SSRF protection
 */
const PRIVATE_IP_RANGES = [
    // IPv4 private ranges
    /^10\./,                           // 10.0.0.0/8
    /^172\.(1[6-9]|2[0-9]|3[0-1])\./,  // 172.16.0.0/12
    /^192\.168\./,                     // 192.168.0.0/16
    /^127\./,                          // 127.0.0.0/8 (localhost)
    /^169\.254\./,                     // 169.254.0.0/16 (link-local)
    /^0\.0\.0\.0/,                     // 0.0.0.0/8
    // IPv6 private ranges
    /^::1$/,                           // localhost
    /^fc00:/i,                         // fc00::/7 (unique local)
    /^fe80:/i,                         // fe80::/10 (link-local)
    /^::$/,                            // ::
];

/**
 * Validates that a URL is safe to download from.
 * Blocks private IP addresses, localhost, and other dangerous URLs.
 *
 * @param {string} urlString - URL to validate
 * @throws {Error} If URL is invalid or points to a blocked resource
 */
export function validateUrl(urlString) {
    let parsedUrl;
    try {
        parsedUrl = new URL(urlString);
    } catch (e) {
        throw new Error(`Invalid URL: ${urlString}`);
    }

    // Only allow http and https protocols
    if (!['http:', 'https:'].includes(parsedUrl.protocol)) {
        throw new Error(`Unsupported protocol: ${parsedUrl.protocol}`);
    }

    const hostname = parsedUrl.hostname;

    // Check for private IP ranges
    for (const range of PRIVATE_IP_RANGES) {
        if (range.test(hostname)) {
            throw new Error(`Blocked private/internal IP address: ${hostname}`);
        }
    }

    // Block hostname variations that could bypass checks
    const lowerHostname = hostname.toLowerCase();
    if (lowerHostname === 'localhost' || lowerHostname.endsWith('.local') || lowerHostname.endsWith('.localhost')) {
        throw new Error(`Blocked hostname: ${hostname}`);
    }

    // Block IP address variations (decimal, octal, hex)
    // e.g., 2130706433 (decimal for 127.0.0.1), 0x7f000001 (hex)
    if (/^\d+$/.test(hostname) && parseInt(hostname, 10) > 0) {
        // Could be a decimal IP representation
        throw new Error(`Blocked potential decimal IP address: ${hostname}`);
    }
}

/**
 * Loads media from various sources (URL, local file path, or base64 data URI)
 * and returns a buffer with the MIME type.
 *
 * @param {string} media - URL, local file path, or base64 data URI
 * @param {string|null} mimetype - Optional MIME type override
 * @param {string} defaultMime - Default MIME type if none detected
 * @returns {Promise<{buffer: Buffer, mimetype: string}>}
 * @throws {Error} If media payload is missing or format is unsupported
 */
export async function loadMediaBuffer(media, mimetype = null, defaultMime = 'application/octet-stream') {
    if (!media) {
        throw new Error('Media payload missing');
    }

    // URL download
    if (media.startsWith('http')) {
        // Validate URL for SSRF protection
        validateUrl(media);

        console.log('Downloading media from URL:', media);
        const response = await axios({
            method: 'GET',
            url: media,
            responseType: 'arraybuffer',
            timeout: 30000,
            validateStatus: status => status < 500
        });

        if (response.status !== 200) {
            throw new Error(`Failed to download media: ${response.status} ${response.statusText}`);
        }

        return {
            buffer: Buffer.from(response.data),
            mimetype: mimetype || response.headers['content-type']?.split(';')[0] || defaultMime
        };
    }

    // Local file path
    if (fs.existsSync(media)) {
        console.log('Reading local media file:', media);
        const fileData = fs.readFileSync(media);
        return {
            buffer: Buffer.from(fileData),
            mimetype: mimetype || defaultMime
        };
    }

    // Base64 data URI
    if (media.startsWith('data:')) {
        const matches = media.match(/^data:([^;]+);base64,(.+)$/);
        if (!matches || matches.length !== 3) {
            throw new Error('Invalid base64 media data');
        }

        return {
            buffer: Buffer.from(matches[2], 'base64'),
            mimetype: mimetype || matches[1] || defaultMime
        };
    }

    throw new Error('Unsupported media format. Must be a URL, local file path, or data URI');
}

export default {
    loadMediaBuffer,
    validateUrl
};

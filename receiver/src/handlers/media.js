/**
 * Media handling utilities for WhatsApp messages
 */

import axios from 'axios';
import fs from 'fs';

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
            mimetype: mimetype || response.headers['content-type'] || defaultMime
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
    loadMediaBuffer
};
import { describe, expect, it } from '@jest/globals';
import { validateUrl } from '../src/handlers/media.js';

describe('validateUrl', () => {
    it.each([
        'http://127.0.0.1/internal',
        'http://10.0.0.1/internal',
        'http://172.16.0.1/internal',
        'http://192.168.1.1/internal',
        'http://169.254.169.254/latest/meta-data',
        'http://localhost/internal',
        'http://service.local/internal',
        'ftp://example.com/file',
        'not a url',
    ])('rejects unsafe URL %s', (url) => {
        expect(() => validateUrl(url)).toThrow();
    });

    it('accepts a public HTTPS URL', () => {
        expect(() => validateUrl('https://example.com/file.png')).not.toThrow();
    });
});

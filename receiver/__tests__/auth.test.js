import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { verifyApiKey } from '../src/middleware/auth.js';

const originalApiKey = process.env.RECEIVER_API_KEY;

function response() {
    const res = {
        status: jest.fn(),
        json: jest.fn(),
    };
    res.status.mockReturnValue(res);
    res.json.mockReturnValue(res);
    return res;
}

function request(headers = {}) {
    return {
        headers,
        ip: '127.0.0.1',
        path: '/send-message',
    };
}

afterEach(() => {
    if (originalApiKey === undefined) {
        delete process.env.RECEIVER_API_KEY;
    } else {
        process.env.RECEIVER_API_KEY = originalApiKey;
    }
    jest.restoreAllMocks();
});

describe('verifyApiKey', () => {
    it('fails closed when no server-side key is configured', () => {
        delete process.env.RECEIVER_API_KEY;
        const res = response();
        const next = jest.fn();
        jest.spyOn(console, 'error').mockImplementation(() => {});

        verifyApiKey(request({ 'x-api-key': 'client-key' }), res, next);

        expect(res.status).toHaveBeenCalledWith(503);
        expect(next).not.toHaveBeenCalled();
    });

    it('rejects a request without a key', () => {
        process.env.RECEIVER_API_KEY = 'expected-key';
        const res = response();
        const next = jest.fn();
        jest.spyOn(console, 'warn').mockImplementation(() => {});

        verifyApiKey(request(), res, next);

        expect(res.status).toHaveBeenCalledWith(401);
        expect(next).not.toHaveBeenCalled();
    });

    it('accepts the configured key and a bearer token', () => {
        process.env.RECEIVER_API_KEY = 'expected-key';
        const next = jest.fn();

        verifyApiKey(request({ 'x-api-key': 'expected-key' }), response(), next);
        verifyApiKey(request({ authorization: 'Bearer expected-key' }), response(), next);

        expect(next).toHaveBeenCalledTimes(2);
    });

    it('rejects a wrong key', () => {
        process.env.RECEIVER_API_KEY = 'expected-key';
        const res = response();
        const next = jest.fn();
        jest.spyOn(console, 'warn').mockImplementation(() => {});

        verifyApiKey(request({ 'x-api-key': 'wrong-key' }), res, next);

        expect(res.status).toHaveBeenCalledWith(401);
        expect(next).not.toHaveBeenCalled();
    });
});

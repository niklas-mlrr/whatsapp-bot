const axios = require('axios');
const FormData = require('form-data');
const fs = require('fs');
const path = require('path');
const config = require('./config');
const logger = require('./logger').createChildLogger('apiClient');

// Create an axios instance with default config
const apiClient = axios.create({
    baseURL: config.backend.apiUrl,
    timeout: config.backend.timeoutMs,
    headers: {
        'Content-Type': 'application/json',
        'X-API-KEY': config.backend.apiKey,
        'User-Agent': `WhatsAppBot/${process.env.npm_package_version || '1.0.0'}`,
    },
    maxContentLength: config.media.maxSizeMB * 1024 * 1024, // Convert MB to bytes
    maxBodyLength: config.media.maxSizeMB * 1024 * 1024, // Convert MB to bytes
    validateStatus: (status) => status >= 200 && status < 500, // Don't throw on 4xx errors
});

// Add request interceptor for logging
apiClient.interceptors.request.use(
    (config) => {
        logger.debug({
            method: config.method.toUpperCase(),
            url: config.url,
            headers: config.headers,
            data: config.data ? '[...]' : undefined, // Don't log full request body
        }, 'Outgoing API request');
        
        return config;
    },
    (error) => {
        logger.error({ error: error.message }, 'Request error');
        return Promise.reject(error);
    }
);

// Add response interceptor for logging and error handling
apiClient.interceptors.response.use(
    (response) => {
        logger.debug({
            status: response.status,
            statusText: response.statusText,
            url: response.config.url,
            data: response.data,
        }, 'API response');
        
        return response;
    },
    (error) => {
        const errorData = {
            message: error.message,
            code: error.code,
            config: {
                method: error.config?.method,
                url: error.config?.url,
                timeout: error.config?.timeout,
            },
        };

        if (error.response) {
            // The request was made and the server responded with a status code
            // that falls out of the range of 2xx
            errorData.response = {
                status: error.response.status,
                statusText: error.response.statusText,
                data: error.response.data,
                headers: error.response.headers,
            };
        } else if (error.request) {
            // The request was made but no response was received
            errorData.request = {
                host: error.request.host,
                path: error.request.path,
                method: error.request.method,
            };
        }

        logger.error(errorData, 'API request failed');
        return Promise.reject(error);
    }
);

/**
 * Sends data to the backend API with retry logic.
 * @param {Object} data - The data to send.
 * @param {Object} options - Additional options.
 * @param {number} [options.retryCount=0] - Current retry count.
 * @returns {Promise<Object>} The response data.
 */
const sendToBackend = async (data, options = {}) => {
    const { retryCount = 0 } = options;
    const maxRetries = config.backend.maxRetries;
    const retryDelay = config.backend.retryDelayMs;

    try {
        const response = await apiClient.post('', data);
        
        // Handle non-2xx status codes
        if (response.status >= 400) {
            throw new Error(`Request failed with status ${response.status}: ${response.statusText}`);
        }
        
        return response.data;
    } catch (error) {
        // Check if we should retry
        const shouldRetry = 
            retryCount < maxRetries && 
            (!error.response || (error.response.status >= 500 && error.response.status < 600));
        
        if (shouldRetry) {
            const nextRetry = retryCount + 1;
            const delay = retryDelay * Math.pow(2, nextRetry - 1);
            
            logger.warn({
                attempt: nextRetry,
                maxAttempts: maxRetries,
                delayMs: delay,
                error: error.message,
            }, 'Retrying failed request');
            
            // Wait before retrying
            await new Promise(resolve => setTimeout(resolve, delay));
            return sendToBackend(data, { ...options, retryCount: nextRetry });
        }
        
        // If we're not retrying, rethrow the error
        throw error;
    }
};

/**
 * Sends a message to the backend API.
 * @param {Object} message - The message to send.
 * @returns {Promise<Object>} The response from the backend.
 */
const sendMessage = async (message) => {
    try {
        const response = await sendToBackend({
            type: 'message',
            timestamp: new Date().toISOString(),
            ...message,
        });
        
        return response;
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            messageId: message.messageId,
        }, 'Failed to send message to backend');
        throw error;
    }
};

/**
 * Uploads a file to the backend.
 * @param {string} filePath - Path to the file to upload.
 * @param {Object} metadata - Additional metadata for the file.
 * @returns {Promise<Object>} The response from the backend.
 */
const uploadFile = async (filePath, metadata = {}) => {
    try {
        const formData = new FormData();
        
        // Add file
        formData.append('file', fs.createReadStream(filePath), {
            filename: path.basename(filePath),
            contentType: metadata.mimetype || 'application/octet-stream',
        });
        
        // Add metadata
        Object.entries(metadata).forEach(([key, value]) => {
            if (value !== undefined) {
                formData.append(key, value);
            }
        });
        
        const response = await apiClient.post('/upload', formData, {
            headers: {
                ...formData.getHeaders(),
                'Content-Length': (await fs.promises.stat(filePath)).size,
            },
            maxContentLength: config.media.maxSizeMB * 1024 * 1024,
            maxBodyLength: config.media.maxSizeMB * 1024 * 1024,
        });
        
        return response.data;
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            filePath,
            metadata,
        }, 'Failed to upload file to backend');
        throw error;
    }
};

// For backward compatibility
const sendToPHP = async (payload) => {
    const logPayload = { ...payload };
    if (logPayload.media) {
        logPayload.media = `[Base64 Data of ${logPayload.mimetype}, length: ${payload.media.length}]`;
    }
    
    logger.debug({ payload: logPayload }, 'Sending message to backend');
    
    try {
        const response = await sendMessage({
            sender: payload.from,  // Map 'from' to 'sender' for the backend
            chat: payload.from,    // Use the same value for chat as sender for direct messages
            type: payload.type,
            content: payload.body, // Use 'content' instead of 'body' to match backend
            sending_time: new Date().toISOString(), // Add current timestamp
            media: payload.media,
            mimetype: payload.mimetype,
            messageId: payload.messageId,
            messageTimestamp: payload.messageTimestamp || new Date().toISOString(),
        });
        
        return true;
    } catch (error) {
        logger.error({
            error: error.message,
            stack: error.stack,
            url: config.backend.apiUrl,
        }, 'Failed to send message to backend');
        return false;
    }
};

module.exports = { 
    sendToBackend, 
    sendMessage, 
    uploadFile,
    sendToPHP,
    apiClient,
};
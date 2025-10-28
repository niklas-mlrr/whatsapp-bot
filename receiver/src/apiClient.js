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
        'X-Webhook-Secret': process.env.WEBHOOK_SECRET || config.backend.webhookSecret || '',
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
        // Send message directly without wrapping - backend expects exact fields
        const response = await sendToBackend(message);
        
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
    
    logger.debug({ 
        payload: {
            ...logPayload,
            senderProfilePictureUrl: logPayload.senderProfilePictureUrl ? '[URL present]' : null,
            senderBio: logPayload.senderBio ? '[Bio present]' : null
        }
    }, 'Sending message to backend');
    
    try {
        const messageData = {
            sender: payload.senderJid || payload.from, // Prefer group participant when provided
            chat: payload.chat || payload.from,        // Prefer explicit chat JID (group) when provided
            type: payload.type,
            content: payload.body !== undefined ? String(payload.body) : '', // Ensure string content
            sending_time: payload.messageTimestamp 
                ? new Date(payload.messageTimestamp * 1000).toISOString() 
                : new Date().toISOString(), // Convert timestamp to ISO string
            media: payload.media || null,
            mimetype: payload.mimetype || null,
            messageId: payload.messageId || null,
            fileName: payload.fileName || null,
            mediaSize: payload.mediaSize || null,
            reactedMessageId: payload.reactedMessageId || null,
            emoji: payload.emoji || null,
            senderJid: payload.senderJid || null,
            quotedMessage: payload.quotedMessage || null,  // Include quoted message data
            senderProfilePictureUrl: (payload.senderProfilePictureUrl ?? null),  // Sender's WhatsApp profile picture
            senderBio: (payload.senderBio ?? null),  // Sender's WhatsApp bio/status
            pollData: payload.pollData || null,  // Poll data for poll messages
            pollMessageId: payload.pollMessageId || null,  // Poll message ID for poll updates
        };
        
        logger.debug({ 
            messageData: { 
                ...messageData, 
                media: messageData.media ? '[base64 data]' : null,
                senderProfilePictureUrl: messageData.senderProfilePictureUrl ? '[URL present]' : null,
                senderBio: messageData.senderBio ? '[Bio present]' : null
            }
        }, 'Sending message data to backend');
        
        const response = await sendMessage(messageData);
        
        logger.debug({ 
            status: response.status,
            hasProfileData: !!messageData.senderProfilePictureUrl || !!messageData.senderBio
        }, 'Message sent to backend successfully');
        
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

/**
 * Updates the status of a message in the backend.
 * @param {string} whatsappMessageId - The WhatsApp message ID.
 * @param {string} status - The new status (sent, delivered, read, failed).
 * @returns {Promise<Object>} The response from the backend.
 */
const updateMessageStatus = async (whatsappMessageId, status) => {
    try {
        // Extract base URL without the webhook path
        const baseUrl = config.backend.apiUrl.replace(/\/api\/whatsapp-webhook\/?$/, '');
        
        // Find the message by WhatsApp message ID and update its status
        const response = await axios.post(`${baseUrl}/api/messages/update-status`, {
            whatsapp_message_id: whatsappMessageId,
            status: status,
        }, {
            headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Secret': process.env.WEBHOOK_SECRET || config.backend.webhookSecret || '',
            },
            timeout: config.backend.timeoutMs,
            validateStatus: (status) => status >= 200 && status < 500,
        });
        
        if (response.status >= 400) {
            // 404 is expected for edit/protocol messages which generate new IDs
            // Don't log these at all to avoid spam
            return null;
        }
        
        logger.debug({ whatsappMessageId, status }, 'Message status updated successfully');
        return response.data;
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            whatsappMessageId,
            status,
        }, 'Error updating message status');
        // Don't throw - status updates are not critical
        return null;
    }
};

/**
 * Notifies the backend that a message was edited by another user
 * @param {string} whatsappMessageId - The WhatsApp message ID
 * @param {string} newContent - The new message content
 * @returns {Promise<Object>} The response from the backend
 */
const notifyMessageEdited = async (whatsappMessageId, newContent) => {
    try {
        logger.info({ whatsappMessageId, newContent }, 'Notifying backend of message edit');
        
        const baseUrl = config.backend.apiUrl.replace(/\/api\/whatsapp-webhook\/?$/, '');
        
        const response = await axios.post(`${baseUrl}/api/messages/notify-edit`, {
            whatsapp_message_id: whatsappMessageId,
            content: newContent,
        }, {
            headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Secret': process.env.WEBHOOK_SECRET || config.backend.webhookSecret || '',
            },
            timeout: config.backend.timeoutMs,
        });
        
        logger.debug({ whatsappMessageId }, 'Message edit notification sent successfully');
        return response.data;
    } catch (error) {
        logger.error({ 
            error: error.message, 
            whatsappMessageId,
            newContent,
        }, 'Error notifying message edit');
        return null;
    }
};

/**
 * Notifies the backend that a message was deleted by another user
 * @param {string} whatsappMessageId - The WhatsApp message ID
 * @returns {Promise<Object>} The response from the backend
 */
const notifyMessageDeleted = async (whatsappMessageId) => {
    try {
        logger.info({ whatsappMessageId }, 'Notifying backend of message deletion');
        
        const baseUrl = config.backend.apiUrl.replace(/\/api\/whatsapp-webhook\/?$/, '');
        
        const response = await axios.post(`${baseUrl}/api/messages/notify-delete`, {
            whatsapp_message_id: whatsappMessageId,
        }, {
            headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Secret': process.env.WEBHOOK_SECRET || config.backend.webhookSecret || '',
            },
            timeout: config.backend.timeoutMs,
        });
        
        logger.debug({ whatsappMessageId }, 'Message deletion notification sent successfully');
        return response.data;
    } catch (error) {
        logger.error({ 
            error: error.message, 
            whatsappMessageId,
        }, 'Error notifying message deletion');
        return null;
    }
};

/**
 * Sends group metadata to the backend when user is added to a group
 * @param {Object} groupData - Group metadata
 * @returns {Promise<Object>} The response from the backend
 */
const sendGroupMetadata = async (groupData) => {
    try {
        const baseUrl = config.backend.apiUrl.replace(/\/api\/whatsapp-webhook\/?$/, '');
        
        const payload = {
            group_id: groupData.groupId,
            name: groupData.groupName,
            description: groupData.groupDescription || '',
            profile_picture_url: groupData.groupProfilePictureUrl || null,
            created_at: groupData.createdAt
        };
        if (Array.isArray(groupData.participants) && groupData.participants.length > 0) {
            payload.participants = groupData.participants;
        }

        const response = await axios.post(`${baseUrl}/api/whatsapp-groups/create`, payload, {
            headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Secret': process.env.WEBHOOK_SECRET || config.backend.webhookSecret || '',
            },
            timeout: config.backend.timeoutMs,
            validateStatus: (status) => status >= 200 && status < 500,
        });
        
        if (response.status >= 400) {
            logger.warn({ status: response.status, data: response.data }, 'Group metadata endpoint returned error');
            return null;
        }
        
        logger.info({ groupId: groupData.groupId }, 'Group metadata sent successfully');
        return response.data;
    } catch (error) {
        logger.error({ 
            error: error.message, 
            stack: error.stack,
            groupId: groupData.groupId,
        }, 'Error sending group metadata to backend');
        return null;
    }
};

module.exports = { 
    sendToBackend, 
    sendMessage, 
    uploadFile,
    sendToPHP,
    updateMessageStatus,
    notifyMessageEdited,
    notifyMessageDeleted,
    sendGroupMetadata,
    apiClient,
};
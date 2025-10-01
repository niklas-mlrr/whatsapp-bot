const { connectToWhatsApp, setReconnectCallback } = require('./src/whatsappClient');
const express = require('express');
const bodyParser = require('body-parser');
const axios = require('axios');
const fs = require('fs');
const { promisify } = require('util');
const stream = require('stream');

const pipeline = promisify(stream.pipeline);

let sockInstance = null;
let isConnected = false;

// Function to set the socket instance
function setSocketInstance(sock) {
    sockInstance = sock;
    
    // Listen for connection updates
    if (sock.ev && sock.ev.on) {
        sock.ev.on('connection.update', (update) => {
            if (update.connection === 'open') {
                isConnected = true;
                console.log('Socket connected and ready.');
            } else if (update.connection === 'close') {
                isConnected = false;
                console.log('Socket connection closed.');
                // Reconnection is handled in whatsappClient.js
            }
        });
    }
}

async function start() {
    // Set up the reconnect callback before initial connection
    setReconnectCallback((newSock) => {
        setSocketInstance(newSock);
    });
    
    const sock = await connectToWhatsApp();
    setSocketInstance(sock);

    const app = express();
    app.use(bodyParser.json({ limit: '10mb' }));

    app.post('/send-message', async (req, res) => {
        console.log('Received send-message request:', {
            chat: req.body.chat,
            type: req.body.type,
            contentLength: req.body.content?.length,
            mediaType: req.body.media ? 'present' : 'missing',
            mimetype: req.body.mimetype
        });

        if (!sockInstance) {
            const error = 'WhatsApp socket not initialized';
            console.error(error);
            return res.status(500).json({ error });
        }
        
        if (!isConnected) {
            const error = 'WhatsApp socket not connected';
            console.error(error);
            return res.status(500).json({ error });
        }

        const { chat, type, content, media, mimetype } = req.body;
        
        // Validate required fields
        if (!chat || !type) {
            const error = 'Missing required fields: chat and type are required';
            console.error(error);
            return res.status(400).json({ error });
        }

        try {
            if (type === 'text') {
                console.log('Sending text message to', chat);
                await sockInstance.sendMessage(chat, { text: content || '' });
            } else if (type === 'image' && media) {
                console.log('Processing image message for', chat);
                try {
                    // Check if media is a URL, local file, or base64 data
                    if (media.startsWith('http')) {
                        console.log('Downloading image from URL:', media);
                        // Download the image from the URL
                        const response = await axios({
                            method: 'GET',
                            url: media,
                            responseType: 'arraybuffer',
                            timeout: 30000, // 30 seconds timeout
                            validateStatus: status => status < 500 // Don't throw for 4xx errors
                        });

                        if (response.status !== 200) {
                            throw new Error(`Failed to download image: ${response.status} ${response.statusText}`);
                        }
                        
                        // Get the actual MIME type from response headers if not provided
                        const actualMimetype = mimetype || 
                                            response.headers['content-type'] || 
                                            'image/jpeg';
                        
                        console.log('Sending image to WhatsApp');
                        // Send the image to WhatsApp
                        await sockInstance.sendMessage(
                            chat, 
                            { 
                                image: response.data, 
                                mimetype: actualMimetype,
                                caption: content || '' 
                            },
                            { 
                                quoted: null,
                                upload: true
                            }
                        );
                    } else if (fs.existsSync(media)) {
                        console.log('Reading local file:', media);
                        // Read the local file
                        const fileData = fs.readFileSync(media);
                        const actualMimetype = mimetype || 'image/jpeg';
                        
                        console.log('Sending local file to WhatsApp');
                        await sockInstance.sendMessage(
                            chat,
                            {
                                image: fileData,
                                mimetype: actualMimetype,
                                caption: content || ''
                            },
                            {
                                quoted: null,
                                upload: true
                            }
                        );
                    } else if (media.startsWith('data:')) {
                        console.log('Processing base64 image data');
                        // Handle base64 data URL
                        const matches = media.match(/^data:([A-Za-z-+\/]+);base64,(.+)$/);
                        if (!matches || matches.length !== 3) {
                            throw new Error('Invalid base64 image data');
                        }
                        
                        const buffer = Buffer.from(matches[2], 'base64');
                        const actualMimetype = mimetype || matches[1];
                        
                        console.log('Sending base64 image to WhatsApp', {
                            size: buffer.length,
                            mimetype: actualMimetype
                        });
                        
                        // Create message object with media
                        const message = {
                            image: buffer,
                            mimetype: actualMimetype,
                            caption: content || ''
                        };
                        
                        // Send the message with the correct options
                        const sendOptions = { quoted: null };
                        await sockInstance.sendMessage(chat, message, sendOptions);
                    } else {
                        throw new Error('Unsupported media format. Must be a URL or data URI');
                    }
                } catch (error) {
                    console.error('Error processing image:', {
                        error: error.message,
                        stack: error.stack,
                        mediaType: typeof media,
                        mediaLength: media?.length,
                        mediaStart: media?.substring(0, 100)
                    });
                    throw new Error(`Failed to process image: ${error.message}`);
                }
            } else {
                const error = 'Unsupported message type or missing media for image type';
                console.error(error);
                return res.status(400).json({ error });
            }
            
            console.log('Message sent successfully to', chat);
            res.json({ status: 'sent' });
            
        } catch (err) {
            console.error('Failed to send message:', {
                error: err.message,
                stack: err.stack,
                chat,
                type,
                hasMedia: !!media,
                mediaType: media?.substring(0, 20) + (media?.length > 20 ? '...' : '')
            });
            res.status(500).json({ 
                error: 'Failed to send message', 
                details: err.message,
                type: err.name
            });
        }
    });

    const PORT = process.env.PORT || 3000;
    app.listen(PORT, () => {
        console.log(`Express server listening on port ${PORT}`);
    });
}

// Start the application
start().catch(err => {
    console.error("Unhandled Error during initial connectToWhatsApp: ", err);
    process.exit(1);
});
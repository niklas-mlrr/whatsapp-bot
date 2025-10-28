# WebSocket Setup Guide

## Problem

When refreshing the webpage, you may see this error:
```
WebSocket connection to 'ws://localhost:8080/app/...' failed
```

This error occurs because the WebSocket server (Reverb or Soketi) is not running.

## Solution

You need to start the WebSocket server. The app supports two options:

### Option 1: Laravel Reverb (Recommended)

1. Open a new terminal window
2. Navigate to the backend directory:
   ```bash
   cd backend
   ```
3. Start the Reverb server:
   ```bash
   start-reverb.bat
   ```
   Or run directly:
   ```bash
   php artisan reverb:start --host=127.0.0.1 --port=8080 --debug
   ```

### Option 2: Soketi

1. Open a new terminal window
2. Navigate to the backend directory:
   ```bash
   cd backend
   ```
3. Start the Soketi server:
   ```bash
   start-soketi.bat
   ```
   Or run directly:
   ```bash
   npx soketi start --config=soketi.json
   ```

## Configuration

Make sure your environment variables are set correctly:

### Backend `.env`:
```
BROADCAST_DRIVER=reverb
REVERB_APP_ID=whatsapp-bot
REVERB_APP_KEY=whatsapp-bot-key
REVERB_APP_SECRET=whatsapp-bot-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Frontend `.env`:
```
VITE_REVERB_APP_KEY=whatsapp-bot-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```


## Improvements Made

The app now handles WebSocket connection failures gracefully:

1. **Graceful Degradation**: App continues to work even if WebSocket fails
2. **Automatic Retries**: Automatically retries connection up to 3 times
3. **Better Logging**: Clear console messages explain what's happening
4. **No Crash**: App doesn't crash if WebSocket server isn't running

## Verification

After starting the WebSocket server, check the browser console:
- ✅ Success: "WebSocket connected successfully"
- ❌ Failure: "Failed to connect to WebSocket - app will continue without real-time updates"

The app will still work for basic functionality, but real-time features (live message updates, typing indicators, etc.) will not be available until the WebSocket server is running.


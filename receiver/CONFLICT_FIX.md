# WhatsApp Conflict Error Fix

## Problem
The receiver was experiencing continuous **"Stream Errored (conflict)"** errors causing an infinite reconnection loop.

## Root Cause
**Multiple simultaneous connection attempts** to WhatsApp were racing against each other:

1. **Duplicate Reconnection Logic**: Both `index.js` and `whatsappClient.js` had their own reconnection handlers
2. **No Connection State Management**: No guard to prevent concurrent connection attempts
3. **Race Condition**: When one connection succeeded, another would try to connect and trigger a conflict

## Solution Applied

### 1. Connection State Management (`whatsappClient.js`)
- Added `isReconnecting` flag to prevent concurrent connection attempts
- Added `currentSocket` reference to track active socket
- Return existing socket if reconnection already in progress

### 2. Longer Backoff for Conflicts
- **10 seconds** delay for conflict errors (status code 440)
- **5 seconds** delay for other errors
- This prevents rapid reconnection attempts that trigger more conflicts

### 3. Removed Duplicate Logic (`index.js`)
- Removed the `setTimeout()` reconnection logic from `setSocketInstance()`
- All reconnection now handled centrally in `whatsappClient.js`

### 4. Callback System
- Added `setReconnectCallback()` to notify `index.js` when socket reconnects
- Ensures `sockInstance` is updated after automatic reconnections

## Testing
1. Stop any running receiver instances
2. Start fresh: `node index.js`
3. Monitor logs for successful connection without conflict loops

## Prevention Tips
- **Never run multiple receiver instances** on the same phone number
- Check for zombie processes: `ps aux | grep node`
- If conflicts persist, check WhatsApp Web sessions on your phone: Settings → Linked Devices
- Delete stale auth sessions if needed: `rm -rf ./baileys_auth_info`

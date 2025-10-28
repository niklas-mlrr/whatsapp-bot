# WhatsApp Community Group Decryption Fix

## Problem

Messages from groups within WhatsApp communities (sub-groups, not the default "Announcements" group) were failing to decrypt with these errors:

```
SessionError: No matching sessions found for message
PreKeyError: Invalid PreKey ID
```

The error showed:
- `remoteJid`: Phone number (`4917646765869@s.whatsapp.net`)
- `senderLid`: LID format (`34252380962990@lid`)
- Message content was empty (decryption failed)

## Root Cause

WhatsApp communities use a different encryption model than regular groups:

1. **Sender Key Distribution**: Community sub-groups require proper sender key distribution messages to establish encryption sessions
2. **LID (Lidded JID)**: Community participants use LID identifiers that need to be mapped to phone numbers
3. **Session Management**: When a new participant joins or the encryption keys rotate, the receiver needs to request retries to get the proper keys

## Solution Implemented

### 1. Enhanced Socket Configuration (`whatsappClient.js`)

Added community-specific socket options:

```javascript
shouldIgnoreJid: () => false,        // Don't ignore any JIDs
retryRequestDelayMs: 250,            // Retry failed decryptions quickly
maxMsgRetryCount: 5,                 // Allow more retries for community messages
```

These settings ensure:
- All JIDs (including LID formats) are processed
- Failed decryptions are retried quickly
- Multiple retry attempts are allowed

### 2. Automatic Retry Requests (`messageHandler.js`)

When a group message fails to decrypt (no message content), the handler now:

1. **Detects the failure**: Checks if `msg.message` is empty for group messages
2. **Logs detailed info**: Records `remoteJid`, `participant`, `senderLid`, etc.
3. **Sends retry receipt**: Automatically requests the sender to resend with proper encryption keys

```javascript
if (!msg.message && isGroup) {
    await sock.sendReceipt(remoteJid, msg.key.participant || msg.key.remoteJid, [msg.key.id], 'retry');
}
```

This tells WhatsApp to:
- Send the sender key distribution message
- Resend the original message with proper encryption
- Establish the encryption session for future messages

### 3. Enhanced Logging

Added detailed logging for community group decryption issues:

- Logs participant information (phone JID and LID)
- Tracks retry attempts
- Shows when sender keys are being requested

## How It Works

1. **Message arrives** from community sub-group
2. **Decryption fails** (no encryption session exists)
3. **Retry receipt sent** to request sender keys
4. **Sender key distribution** message arrives
5. **Original message resent** with proper encryption
6. **Message decrypts successfully** and is processed normally

## Testing

After applying this fix:

1. Restart the receiver: `pm2 restart node-server`
2. Send a message in a community sub-group
3. Check logs for:
   - "Skipping group message with no content - decryption failed (requesting retry)"
   - "Sent retry receipt for failed decryption"
   - Message should appear after retry (within a few seconds)

## Expected Behavior

- **First message** from a new community group may fail initially
- **Retry request** is sent automatically
- **Message arrives** successfully after 1-5 seconds
- **Subsequent messages** decrypt immediately (session established)

## Files Modified

1. `receiver/src/whatsappClient.js`:
   - Added `shouldIgnoreJid`, `retryRequestDelayMs`, `maxMsgRetryCount` to socket config

2. `receiver/src/messageHandler.js`:
   - Added automatic retry receipt sending for failed group message decryption
   - Enhanced logging for community group messages

## Technical Details

### Why Community Groups Are Different

- **Regular groups**: Use standard group encryption with pre-shared keys
- **Community sub-groups**: Use sender key distribution with dynamic key rotation
- **LID identifiers**: Community participants may use LID format that needs mapping

### Baileys Library Behavior

The `@whiskeysockets/baileys` library (v6.7.20) handles:
- Automatic session management
- Sender key distribution processing
- Retry mechanism when `sendReceipt(..., 'retry')` is called

Our fix ensures these mechanisms are properly triggered for community groups.

## Future Improvements

If issues persist:

1. **Increase retry count**: Change `maxMsgRetryCount` from 5 to 10
2. **Add delay**: Wait longer before sending retry receipt
3. **Pre-fetch keys**: Proactively request sender keys when joining community groups
4. **Session persistence**: Ensure auth state properly saves community group sessions

## Related Issues

- Community "Announcements" groups work fine (different encryption model)
- Direct messages work fine (standard E2E encryption)
- Regular groups work fine (standard group encryption)
- Only community **sub-groups** had this issue

# Receiver URL Configuration

## Environment Variable

The `RECEIVER_URL` should be set to the **base URL only** without any path:

```env
RECEIVER_URL=http://192.168.178.149:3000
```

**NOT:**
```env
RECEIVER_URL=http://192.168.178.149:3000/send-message  ❌
```

## Usage in Code

The backend code appends the appropriate endpoint path to the base URL:

### 1. Sending Messages (`WhatsAppMessageController.php`)
```php
$receiverUrl = env('RECEIVER_URL');
$response = $http->post("{$receiverUrl}/send-message", $sendPayload);
```
**Full URL:** `http://192.168.178.149:3000/send-message`

### 2. Sending Reactions (`MessageStatusController.php`)
```php
$receiverUrl = env('RECEIVER_URL', 'http://localhost:3000');
$response = Http::timeout(10)->post("{$receiverUrl}/send-reaction", [
    'chat' => $chatJid,
    'messageId' => $whatsappMessageId,
    'emoji' => $emoji
]);
```
**Full URL:** `http://192.168.178.149:3000/send-reaction`

## Files Modified

### Backend
- ✅ `backend/app/Http/Controllers/Api/WhatsAppMessageController.php` - Added `/send-message` to URL
- ✅ `backend/app/Http/Controllers/Api/MessageStatusController.php` - Already has `/send-reaction` appended

## Receiver Endpoints

The receiver (`receiver/index.js`) has these endpoints:

1. **POST `/send-message`** - Send messages to WhatsApp
   - Used by: `WhatsAppMessageController.php`
   
2. **POST `/send-reaction`** - Send reactions to WhatsApp
   - Used by: `MessageStatusController.php`

3. **GET `/status`** - Health check
   - Used for: Checking if receiver is running

## Configuration Summary

| Environment Variable | Value | Purpose |
|---------------------|-------|---------|
| `RECEIVER_URL` | `http://192.168.178.149:3000` | Base URL of the receiver service |
| `RECEIVER_TLS_INSECURE` | `false` (optional) | Allow self-signed certificates for HTTPS |

## Testing

### Test Message Sending
```bash
# From web interface, send a message
# Check backend logs for: "Sending message to receiver"
# Should show URL: http://192.168.178.149:3000/send-message
```

### Test Reaction Sending
```bash
# From web interface, react to a message
# Check backend logs for: "Reaction sent to WhatsApp"
# Should show URL: http://192.168.178.149:3000/send-reaction
```

### Test Receiver Status
```bash
curl http://192.168.178.149:3000/status
```

## Troubleshooting

### Error: "Cannot POST /send-message/send-reaction"
**Cause:** `RECEIVER_URL` includes `/send-message` in the URL
**Fix:** Remove `/send-message` from `RECEIVER_URL` in `.env`

### Error: Connection refused
**Cause:** Receiver is not running or wrong IP/port
**Fix:** 
1. Check receiver is running: `curl http://192.168.178.149:3000/status`
2. Verify IP address matches your receiver's IP
3. Verify port 3000 is correct

### Error: 404 Not Found
**Cause:** Wrong endpoint path
**Fix:** Ensure code appends correct path (`/send-message` or `/send-reaction`)

# Reaction Sending/Receiving Fix

## Problem
Reactions were only showing locally in the database but not being sent to/received from WhatsApp recipients. Users could see their own reactions in the web interface, but:
- Reactions sent from web interface didn't appear in WhatsApp mobile
- Reactions sent from WhatsApp mobile didn't appear in web interface

## Root Cause
The system was missing the functionality to:
1. **Send reactions to WhatsApp** - When a user reacts via web interface, it only updated the database
2. **Receive reactions from WhatsApp** - The receiver had the handler but wasn't properly integrated

## Solution

### 1. Added Reaction Sending to Receiver (`receiver/index.js`)

Created a new `/send-reaction` endpoint that sends reactions to WhatsApp:

```javascript
app.post('/send-reaction', async (req, res) => {
    const { chat, messageId, emoji } = req.body;
    
    const reactionMessage = {
        react: {
            text: emoji || '', // Empty string removes the reaction
            key: {
                remoteJid: chat,
                id: messageId,
                fromMe: false
            }
        }
    };
    
    await sockInstance.sendMessage(chat, reactionMessage);
    res.json({ status: 'sent' });
});
```

**Key Points**:
- Uses Baileys' `react` message type
- Empty emoji string removes the reaction
- Requires the original WhatsApp message ID (not database ID)

### 2. Updated Backend to Send Reactions (`MessageStatusController.php`)

Added `sendReactionToWhatsApp()` method that:
1. Extracts the original WhatsApp message ID from metadata
2. Gets the chat JID (WhatsApp identifier)
3. Calls the receiver's `/send-reaction` endpoint

**Integration Points**:
- Called in `addReaction()` - when user adds a reaction
- Called in `removeReaction()` - when user removes a reaction (with empty emoji)

```php
private function sendReactionToWhatsApp(WhatsAppMessage $message, string $emoji): void
{
    $metadata = $message->metadata ?? [];
    $whatsappMessageId = $metadata['message_id'] ?? null;
    
    $chatModel = \App\Models\Chat::find($message->chat_id);
    $chatJid = $chatModel->name;
    
    $receiverUrl = env('WHATSAPP_RECEIVER_URL', 'http://localhost:3000');
    
    Http::timeout(10)->post("{$receiverUrl}/send-reaction", [
        'chat' => $chatJid,
        'messageId' => $whatsappMessageId,
        'emoji' => $emoji
    ]);
}
```

### 3. Receiving Reactions from WhatsApp

The receiver already had `handleReactionMessage()` in `messageHandler.js`:
- Extracts reaction data from WhatsApp message
- Sends to backend with type='reaction'
- Backend's `WhatsAppMessageService` processes it via `handleReactionMessage()`

## Data Flow

### Outgoing Reaction (Web → WhatsApp)
1. User clicks emoji in web interface
2. Frontend calls `POST /messages/{id}/reactions`
3. Backend updates database
4. Backend calls receiver's `/send-reaction`
5. Receiver sends reaction to WhatsApp via Baileys
6. Backend broadcasts WebSocket event
7. Other web clients see the reaction

### Incoming Reaction (WhatsApp → Web)
1. User reacts in WhatsApp mobile
2. Receiver's `handleReactionMessage()` receives it
3. Receiver sends to backend with type='reaction'
4. Backend's `handleReactionMessage()` updates database
5. Backend broadcasts WebSocket event
6. Web clients see the reaction

## Configuration

Add to `.env`:
```
WHATSAPP_RECEIVER_URL=http://localhost:3000
```

## Testing

### Test Outgoing Reactions
1. Open web interface
2. React to a message with an emoji
3. Check WhatsApp mobile - reaction should appear

### Test Incoming Reactions
1. Open WhatsApp mobile
2. React to a message
3. Check web interface - reaction should appear

### Test Reaction Removal
1. Click the same reaction again (web or mobile)
2. Reaction should disappear from both interfaces

## Files Modified

### Receiver
- ✅ `receiver/index.js` - Added `/send-reaction` endpoint

### Backend
- ✅ `backend/app/Http/Controllers/Api/MessageStatusController.php` - Added `sendReactionToWhatsApp()` method

## Important Notes

- Reactions require the **original WhatsApp message ID** stored in metadata
- Messages sent from the web interface should store their WhatsApp message ID when sent
- The chat JID format is typically `{phone}@s.whatsapp.net` for individual chats
- Empty emoji string is used to remove reactions
- Reactions are sent asynchronously - failures are logged but don't block the API response

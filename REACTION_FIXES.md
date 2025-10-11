# Emoji Reaction Fixes

## Issues Fixed

### Problem 1: Reaction Picker Not Showing
**Issue**: The reaction emoji picker wasn't appearing when clicking the emoji button.

**Root Cause**: 
- Missing click-outside handler to manage picker state
- Positioning issues with the picker
- Missing data attributes for click detection

**Fixes Applied**:
1. Added `data-reaction-button` attribute to both reaction buttons (hover button and "+" button)
2. Added `reaction-picker` class to the picker div
3. Implemented `handleClickOutside()` function to close picker when clicking outside
4. Added event listeners in `onMounted()` and cleanup in `onUnmounted()`
5. Changed z-index from `z-10` to `z-50` for better visibility
6. Improved positioning with `bottom: calc(100% + 4px)`
7. Removed condition `v-if="!hasReactions"` from hover button so it always shows

**Files Modified**:
- `frontend/vue-project/src/components/MessageItem.vue`

### Problem 2: Backend Error When Receiving Reactions
**Issue**: When receiving a reaction from WhatsApp, the backend tried to save it as a message with type "unknown", causing SQL error:
```
SQLSTATE[01000]: Warning: 1265 Data truncated for column 'type' at row 1
```

**Root Cause**:
- `WhatsAppMessageService` didn't have a case for handling 'reaction' type messages
- Reactions were falling through to `handleUnknownMessage()` which tried to create a message record
- `WhatsAppMessageData` DTO was missing reaction-specific fields

**Fixes Applied**:

1. **Updated `WhatsAppMessageService.php`**:
   - Added `'reaction' => $this->handleReactionMessage($data)` to the match statement
   - Created new `handleReactionMessage()` method that:
     - Finds the message that was reacted to
     - Updates the message's `reactions` JSON column
     - Broadcasts the reaction via WebSocket
     - Returns `null` (doesn't create a new message)

2. **Updated `WhatsAppMessageData.php`**:
   - Added `reactedMessageId`, `emoji`, and `senderJid` properties
   - Added `sender_id` and `chat_id` as mutable properties
   - Updated `fromRequest()` to include reaction fields

**Files Modified**:
- `backend/app/Services/WhatsAppMessageService.php`
- `backend/app/DataTransferObjects/WhatsAppMessageData.php`

## Testing

### Test Reaction Picker
1. Hover over any message
2. Click the emoji button that appears
3. Verify the picker shows with 6 emojis
4. Click an emoji to add it
5. Verify the picker closes
6. Click outside the picker to close it

### Test Incoming Reactions
1. Send a message from the web interface
2. React to that message from WhatsApp mobile app
3. Verify the reaction appears under the message in web interface
4. Verify no errors in backend logs
5. Remove the reaction from mobile
6. Verify it disappears from web interface

### Test Outgoing Reactions
1. Send a message from WhatsApp mobile
2. React to it from the web interface
3. Verify the reaction appears in WhatsApp mobile
4. Click the reaction again to remove it
5. Verify it disappears from both interfaces

## Technical Details

### Reaction Flow (Incoming from WhatsApp)
1. WhatsApp sends reaction → Receiver (`messageHandler.js`)
2. Receiver extracts reaction data → Sends to backend API
3. Backend validates type = 'reaction' → Calls `handleReactionMessage()`
4. Method finds target message → Updates `reactions` JSON column
5. Broadcasts `MessageReaction` event via WebSocket
6. Frontend receives event → Updates local message state
7. UI re-renders with new reaction

### Reaction Flow (Outgoing from Web)
1. User clicks emoji button → Picker appears
2. User selects emoji → `addReaction()` called
3. API call to `POST /messages/{id}/reactions`
4. Backend updates message → Broadcasts event
5. Other clients receive update via WebSocket

### Data Structure
```json
{
  "reactions": {
    "user_id_1": "👍",
    "user_id_2": "❤️",
    "user_id_3": "👍"
  }
}
```

## Files Changed

### Backend
- ✅ `backend/app/Services/WhatsAppMessageService.php` - Added reaction handler
- ✅ `backend/app/DataTransferObjects/WhatsAppMessageData.php` - Added reaction fields
- ✅ `backend/app/Events/MessageReaction.php` - Removed invalid chat relationship loading
- ✅ `backend/app/Http/Controllers/Api/ChatController.php` - Added reactions to SQL queries and response formatting

### Frontend
- ✅ `frontend/vue-project/src/components/MessageItem.vue` - Fixed picker visibility and click handling
- ✅ `frontend/vue-project/src/components/MessageList.vue` - Fixed Vue reactivity for reaction updates

## Additional Fixes

### Problem 3: Reactions Not Displaying in UI
**Issue**: Reactions were saved to database but not showing in the chat interface.

**Root Causes**:
1. Backend API wasn't selecting the `reactions` column from database
2. Vue's reactivity wasn't detecting direct property mutations

**Fixes Applied**:

1. **Updated `ChatController.php`**:
   - Added `m.reactions` to all SQL SELECT statements in both `messages()` and `latestMessages()` methods
   - Added reaction decoding logic to parse JSON from database
   - Added `reactions` field to API response

2. **Updated `MessageList.vue`**:
   - Changed direct property mutations to create new objects with spread operator
   - This ensures Vue's reactivity system detects changes and triggers re-renders
   - Applied to both API response handling and WebSocket event handling

## Notes

- Reactions don't create new message records - they update existing messages
- Empty emoji string indicates reaction removal
- The `handleReactionMessage()` method returns `null` to prevent message creation
- Click-outside detection uses data attributes to avoid closing when clicking reaction buttons
- Z-index increased to 50 to ensure picker appears above other elements
- Vue reactivity requires creating new objects rather than mutating nested properties

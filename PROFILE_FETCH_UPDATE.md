# Profile Picture Fetch Update

## Issue
Profile pictures were only being fetched and updated when a contact sent a **direct message**. When the same contact sent a message in a **group chat**, their profile picture was not fetched or updated.

## Solution
Updated the receiver to fetch profile pictures for **both** direct messages and group messages.

## Changes Made

### File: `receiver/src/messageHandler.js`

**Before:**
- Profile fetching only happened for direct chats (`if (!isGroup)`)
- Group message senders' profiles were never fetched

**After:**
- Profile fetching happens for ALL messages (both direct and group)
- For direct chats: uses `remoteJid` (the chat ID)
- For group chats: uses `senderJid` (the participant who sent the message)

### Key Changes:

1. **Moved profile fetch timing** (lines 143-176):
   - Now happens AFTER `senderJid` is determined (important for groups)
   - Uses `profileJid = isGroup ? senderJid : remoteJid`
   - This ensures we fetch the correct person's profile in both scenarios

2. **Profile data flow**:
   ```
   Direct Message:
   Contact → Direct Chat → Fetch profile of remoteJid → Update contact
   
   Group Message:
   Contact → Group Chat → Fetch profile of senderJid (participant) → Update contact
   ```

## Backend Handling

The backend (`WhatsAppMessageController`) already handles this correctly:
- When a message arrives with `senderProfilePictureUrl` and `senderBio`
- It calls `createOrUpdateContact()` which uses `updateOrCreate()`
- This works for both direct and group messages
- The contact is identified by phone number (`sender` field)

## Result

Now when a contact:
1. ✅ Sends a direct message → Profile is fetched and updated
2. ✅ Sends a group message → Profile is fetched and updated
3. ✅ Sends multiple messages in different groups → Profile is updated from any of them

## Testing

To test:
1. Have a contact send a message in a group
2. Check the logs for "Fetched sender profile info" with `isGroup: true`
3. Verify the contact's profile picture is updated in the database
4. Check that the profile appears in the UI when viewing the contact

## Notes

- Profile fetching happens asynchronously and won't block message processing
- Errors in profile fetching are logged but don't fail the message
- Profile data is sent to backend with every message (backend handles deduplication)
- Backend only updates if profile has changed (efficient)

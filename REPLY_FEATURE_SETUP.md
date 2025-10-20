# Reply Feature Setup Instructions

## Overview
The reply/quote message feature has been implemented. To make it work, you need to run the database migration.

## Steps to Enable Reply Feature

### 1. Run the Database Migration

Navigate to the backend directory and run:

```bash
cd backend
php artisan migrate
```

This will add the `reply_to_message_id` column to the `whatsapp_messages` table.

### 2. Verify the Migration

Check that the migration was successful:

```bash
php artisan migrate:status
```

You should see the migration `2025_10_19_210000_add_reply_to_message_id_to_whatsapp_messages` as "Ran".

### 3. Test the Feature

1. Open the frontend application
2. Hover over any message - you should see a reply arrow icon
3. Click the reply arrow - the message should appear above the input field
4. Type your reply and send it
5. The sent message should now display with a quoted message reference at the top

## What Was Changed

### Backend Changes:
1. **Migration**: Added `reply_to_message_id` column to `whatsapp_messages` table
2. **Model** (`WhatsAppMessage.php`):
   - Added `reply_to_message_id` to fillable fields
   - Added `replyToMessage()` relationship method
   - Added `getQuotedMessageAttribute()` accessor
   - Added `quoted_message` to appends array
3. **Controller** (`WhatsAppMessageController.php`):
   - Added `reply_to_message_id` validation
   - Added `reply_to_message_id` to message creation

### Frontend Changes:
1. **MessageItem.vue**:
   - Added reply button that appears on hover
   - Added visual display of quoted messages
   - Added helper functions to extract quoted message data
2. **MessageList.vue**:
   - Added `reply-to-message` event handler
   - Exposed `scrollContainer` and `isScrolledToBottom` refs
3. **MessagesView.vue**:
   - Added reply state management
   - Added reply preview UI above input field
   - Added `reply_to_message_id` to message payload
   - Fixed auto-scroll when input expands
   - Fixed dark mode "Neue Nachrichten" indicator color

## Troubleshooting

If the reply feature doesn't work:

1. **Check migration status**: Run `php artisan migrate:status`
2. **Check database**: Verify the `reply_to_message_id` column exists in `whatsapp_messages` table
3. **Check browser console**: Look for any JavaScript errors
4. **Check Laravel logs**: Check `backend/storage/logs/laravel.log` for any errors
5. **Clear cache**: Run `php artisan cache:clear` and `php artisan config:clear`

## Notes

- The reply feature stores the reference in the database
- The quoted message is automatically loaded when fetching messages
- The visual display shows the sender name and content preview
- Media types (images, videos, etc.) show appropriate icons in the preview

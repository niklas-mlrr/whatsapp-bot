# Debug Image Display Issue

## Current Situation
- Images send successfully
- Image shows as "Image" text instead of actual image in chat
- Clicking opens preview modal but shows "Image preview" text instead of image
- Console error: "Failed to load image: " (empty string)

## Likely Causes

### 1. Storage Symlink Not Created
Laravel requires a symlink from `public/storage` to `storage/app/public` for the `/storage/` URL to work.

**Check**: Open browser and try to access: `http://localhost:8000/storage/uploads/test.jpg` (replace with actual image path)

**Fix**: Run this command in the backend directory:
```bash
php artisan storage:link
```

### 2. API Not Returning Media Field
The backend might not be returning the `media` field in the API response.

**Check**: Look at browser console for the log output added in MessageList.vue:
- "Raw message X:" should show `media` and `mimetype` fields
- If they're `null` or `undefined`, the backend mapping is incorrect

**Fix**: Already applied - ensured all SQL queries select `media_url` and map it to `media` in response

### 3. Frontend URL Construction Issue
The frontend might not be correctly constructing the image URL.

**Check**: In browser console, when MessageItem loads, check if `imageSrc` computed property has the correct value

**Expected**: `/storage/uploads/xxxxx.jpg`
**If empty**: `message.media` is null/undefined

## Steps to Debug

1. **Refresh the frontend** to see the new console logs
2. **Check browser console** for "Raw message X:" logs - look at the `media` field
3. **Test storage link**:
   - Find an actual uploaded image path in the database or logs
   - Try accessing it directly: `http://localhost:8000/storage/uploads/filename.jpg`
   - If 404, run `php artisan storage:link`
4. **Check Network tab** when image fails to load - see what URL is being requested

## Quick Test Commands

### Backend (in d:\z - WhatsAppBot Abiplanung REROLL\backend)
```powershell
# Create storage symlink
php artisan storage:link

# Check if uploads directory exists
dir storage\app\public\uploads

# Query database to see actual media_url values
php artisan tinker
>>> DB::table('whatsapp_messages')->where('type', 'image')->select('id', 'media_url', 'media_type')->get()
```

### Frontend
Open browser console and look for logs showing media field values.

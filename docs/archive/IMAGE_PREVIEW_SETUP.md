# Image Viewing & Preview Feature

## Overview
This feature allows users to view images sent in the chat and click on them for a full-screen preview.

## Components Added

### 1. ImagePreviewModal.vue
- **Location**: `frontend/vue-project/src/components/ImagePreviewModal.vue`
- **Features**:
  - Full-screen image preview (85% of screen size)
  - Centered display with blurred background
  - Keyboard navigation (Esc to close, Arrow keys to navigate)
  - Image navigation buttons
  - Download button
  - Caption display
  - Loading spinner

### 2. MessageItem.vue (Updated)
- Now emits `open-image-preview` event when images are clicked
- Properly displays images in chat messages

### 3. MessageList.vue (Updated)
- Now uses `MessageItem` component instead of inline rendering
- Manages image preview modal state
- Collects all images from chat for gallery navigation

## Backend Requirements

### Storage Link
Make sure the Laravel storage link is created. Run this command in the backend directory:

```bash
php artisan storage:link
```

This creates a symbolic link from `public/storage` to `storage/app/public`, allowing images to be publicly accessible.

### Image Upload Endpoint
The feature assumes images are stored in Laravel's public storage disk and accessible via `/storage/path/to/image.jpg`

## Usage

### For Users
1. **View Images**: Images appear inline in chat messages
2. **Full Preview**: Click any image to open full-screen preview
3. **Navigate**: Use arrow keys or on-screen buttons to navigate between images
4. **Download**: Click download button in preview to save image
5. **Close**: Press Esc or click X button to close preview

### For Developers
To emit the image preview from any component:
```vue
emit('open-image-preview', {
  src: '/storage/images/example.jpg',
  caption: 'Optional caption text'
})
```

## Styling
- Preview modal takes 85% of screen size (as requested)
- Background is blurred with dark overlay
- Chat remains visible but out of focus
- Smooth transitions and animations

## Browser Support
- Modern browsers with CSS backdrop-filter support
- Fallback styling for older browsers

## Testing
1. Send an image in the chat
2. Verify image appears in message
3. Click image to open preview
4. Test keyboard navigation
5. Test download functionality

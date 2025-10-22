# Edit and Delete Message Buttons Implementation

## Overview
Added edit and delete buttons that appear on hover next to messages, similar to the existing reply and reaction buttons.

## Changes Made

### 1. MessageItem.vue (Component)

#### Added Buttons (Lines 359-381)
- **Edit Button**: Appears on hover for own text messages only
  - Icon: Pencil/edit icon
  - Color: Blue on hover
  - Condition: `v-if="isMe && (message.type === 'text' || !message.type)"`
  - Title: "Bearbeiten"

- **Delete Button**: Appears on hover for all own messages
  - Icon: Trash/delete icon
  - Color: Red on hover
  - Condition: `v-if="isMe"`
  - Title: "Löschen"

#### Added Event Emitters (Lines 451-458)
```typescript
const emit = defineEmits<{
  'open-image-preview': [payload: { src: string; caption?: string }]
  'add-reaction': [payload: { messageId: string | number; emoji: string }]
  'remove-reaction': [payload: { messageId: string | number }]
  'reply-to-message': [message: any]
  'edit-message': [message: any]
  'delete-message': [messageId: string | number]
}>()
```

#### Added Handler Functions (Lines 691-701)
```typescript
// Edit method
function handleEditClick() {
  emit('edit-message', props.message)
}

// Delete method
function handleDeleteClick() {
  if (confirm('Möchten Sie diese Nachricht für alle löschen?')) {
    emit('delete-message', props.message.id)
  }
}
```

### 2. MessageList.vue (Parent Component)

#### Added Event Handlers to MessageItem (Lines 70-71)
```vue
<MessageItem 
  @edit-message="handleEditMessage"
  @delete-message="handleDeleteMessage"
/>
```

#### Updated Emits (Line 213)
```typescript
const emit = defineEmits(['load-more', 'message-read', 'typing', 'reply-to-message', 'edit-message']);
```

#### Added Handler Functions (Lines 823-841)
```typescript
// Edit message handler
const handleEditMessage = (message: any) => {
  emit('edit-message', message);
};

// Delete message handler
const handleDeleteMessage = async (messageId: string | number) => {
  try {
    await apiClient.delete(`/messages/${messageId}`);
    
    // Remove message from local state
    messages.value = messages.value.filter(m => m.id !== messageId);
    
    console.log('Message deleted successfully');
  } catch (error) {
    console.error('Failed to delete message:', error);
    alert('Fehler beim Löschen der Nachricht');
  }
};
```

### 3. MessagesView.vue (Top-Level Component)

#### Added Event Handler to MessageList (Line 205)
```vue
<MessageList 
  @edit-message="handleEditMessage"
/>
```

#### Added Handler Function (Lines 1093-1119)
```typescript
const handleEditMessage = async (message: any) => {
  console.log('[MessagesView] Editing message:', message)
  
  // Prompt user for new content
  const newContent = prompt('Nachricht bearbeiten:', message.content)
  
  if (newContent === null || newContent.trim() === '') {
    // User cancelled or entered empty text
    return
  }
  
  if (newContent === message.content) {
    // No changes made
    return
  }
  
  try {
    await apiClient.put(`/messages/${message.id}`, {
      content: newContent.trim()
    })
    
    console.log('Message edited successfully')
  } catch (error) {
    console.error('Failed to edit message:', error)
    alert('Fehler beim Bearbeiten der Nachricht')
  }
}
```

## User Experience

### Edit Button
1. Hover over your own text message
2. Click the blue pencil icon
3. Enter new text in the prompt dialog
4. Message is updated for everyone via backend API
5. WebSocket broadcasts the change to all connected clients

### Delete Button
1. Hover over any of your own messages (text, image, video, etc.)
2. Click the red trash icon
3. Confirm deletion in the dialog
4. Message is deleted from database and WhatsApp
5. Message disappears from UI immediately
6. WebSocket broadcasts deletion to all connected clients

## Visual Design

- **Opacity**: Buttons are hidden by default (`opacity-0`)
- **Hover Effect**: Appear smoothly on message hover (`group-hover:opacity-100`)
- **Transition**: Smooth opacity transition
- **Colors**:
  - Edit: Gray → Blue on hover
  - Delete: Gray → Red on hover
- **Positioning**: Aligned horizontally after reply and reaction buttons
- **Icons**: Clean, minimal SVG icons from Heroicons

## Backend Integration

### Edit Message
- **Endpoint**: `PUT /api/messages/{id}`
- **Payload**: `{ content: string }`
- **Response**: Updated message with `edited_at` timestamp
- **WhatsApp**: Sends edit request to receiver
- **Broadcast**: `MessageEdited` event via WebSocket

### Delete Message
- **Endpoint**: `DELETE /api/messages/{id}`
- **Response**: Success confirmation
- **WhatsApp**: Sends delete request to receiver with `forEveryone: true`
- **Broadcast**: `MessageDeleted` event via WebSocket

## Notes

- Edit button only appears for text messages (media messages can't be edited)
- Delete button appears for all message types
- Both buttons only appear for messages sent by the current user (`isMe` check)
- Confirmation dialog prevents accidental deletions
- Simple prompt dialog for editing (can be enhanced with a modal later)
- Real-time updates ensure all users see changes immediately

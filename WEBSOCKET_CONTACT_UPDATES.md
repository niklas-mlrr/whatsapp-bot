# WebSocket Contact Updates Implementation

## Issues Fixed

### Issue 1: Profile Pictures Required Page Reload
Profile pictures were only visible after reloading the page because there was no real-time update mechanism.

### Issue 2: Profile Pictures Missing in Group Participant List
Group participant lists were fetching avatar URLs from the contacts table, but the frontend wasn't displaying them properly.

## Solution Implemented

### Backend Changes

#### 1. Added WebSocket Broadcasting for Contact Updates
**File:** `backend/app/Services/WebSocketService.php`

Added new method `contactUpdated()`:
```php
public function contactUpdated(\App\Models\Contact $contact): void
{
    // Broadcasts to global 'contacts' channel
    Broadcast::event('contacts', 'contact.updated', [
        'contact' => [
            'id' => $contact->id,
            'phone' => $contact->phone,
            'name' => $contact->name,
            'profile_picture_url' => $contact->profile_picture_url,
            'bio' => $contact->bio,
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ],
        'event' => 'contact.updated',
    ]);
}
```

#### 2. Integrated Broadcasting into Contact Updates
**File:** `backend/app/Services/WhatsAppMessageService.php`

Updated `createOrUpdateContact()` to broadcast changes:
- When existing contact is updated → broadcasts update
- When new contact is created → broadcasts new contact

```php
// After updating existing contact
$this->webSocketService->contactUpdated($existingContact);

// After creating new contact
$this->webSocketService->contactUpdated($contact);
```

### Frontend Changes

#### 1. Added Contact Update Event Type
**File:** `frontend/vue-project/src/services/websocket.ts`

Added new type definition:
```typescript
type ContactUpdateEvent = {
  contact: {
    id: number;
    phone: string;
    name: string;
    profile_picture_url: string | null;
    bio: string | null;
    updated_at: string;
  };
};
```

#### 2. Added Contact Update Listener
**File:** `frontend/vue-project/src/services/websocket.ts`

Added new method `listenForContactUpdates()`:
```typescript
const listenForContactUpdates = (
  callback: (event: ContactUpdateEvent) => void
): (() => void) => {
  // Subscribes to global 'contacts' channel
  // Listens for '.contact.updated' events
  // Calls all registered callbacks with updated contact data
};
```

## How It Works

### Flow for Profile Picture Updates

1. **Message Arrives** (direct or group)
   ```
   Receiver → Backend → WhatsAppMessageService
   ```

2. **Contact Created/Updated**
   ```
   createOrUpdateContact() → Updates database
   ```

3. **WebSocket Broadcast**
   ```
   webSocketService.contactUpdated() → Broadcasts to 'contacts' channel
   ```

4. **Frontend Receives Update**
   ```
   Frontend listening on 'contacts' channel
   → Receives contact.updated event
   → Updates UI instantly (no reload needed)
   ```

## Frontend Integration Required

To complete the implementation, the frontend needs to:

### 1. Listen for Contact Updates in MessagesView or App.vue

```typescript
import { useWebSocket } from '@/services/websocket';

const websocket = useWebSocket();

// Listen for contact updates
onMounted(() => {
  const cleanup = websocket.listenForContactUpdates((event) => {
    console.log('Contact updated:', event.contact);
    
    // Update local contact cache/store
    // Update any displayed profile pictures
    // Refresh group participant lists if needed
  });
  
  onUnmounted(cleanup);
});
```

### 2. Update Contact Store/Cache

Create a contacts store or update existing chat store to:
- Maintain a map of phone → contact data
- Update this map when contact.updated events arrive
- Use this data for displaying profile pictures

### 3. Display Profile Pictures in Group Participants

The backend already returns `avatar_url` in the group members API:
```json
{
  "id": "4917646765869",
  "name": "Contact Name",
  "phone": "4917646765869@s.whatsapp.net",
  "avatar_url": "https://pps.whatsapp.net/..."
}
```

The frontend needs to:
- Display these avatar URLs in the participant list UI
- Update them when contact.updated events arrive

## Testing

### Backend Testing
1. Send a message from a contact (direct or group)
2. Check logs for: `"Contact update broadcast sent"`
3. Verify WebSocket server receives the broadcast

### Frontend Testing
1. Open browser console
2. Send a message from a contact
3. Should see: `[WebSocket] Received contact.updated event:`
4. Profile picture should update instantly without page reload

## Files Modified

### Backend
- `backend/app/Services/WebSocketService.php` - Added contactUpdated() method
- `backend/app/Services/WhatsAppMessageService.php` - Added broadcast calls

### Frontend
- `frontend/vue-project/src/services/websocket.ts` - Added contact update listener

## Status

✅ Backend broadcasting implemented
✅ Frontend WebSocket listener implemented
⚠️ Frontend UI integration needed (listen for events and update UI)
⚠️ Group participant profile pictures need UI display implementation

## Next Steps

1. Add contact update listener in MessagesView or App.vue
2. Create/update contacts store to cache contact data
3. Update UI components to display profile pictures from contacts
4. Implement group participant list with profile pictures

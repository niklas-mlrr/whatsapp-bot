# Debugging Guide - Feature Issues

## How to Debug the Issues

### 1. User's Own Number Not Showing

**Console Output to Check:**
```
Approved chats after sorting: [{...}]
```

**What to look for:**
- Expand the chat object in the console
- Check these fields:
  - `name` - Does it contain any part of "1590 8115183"?
  - `participants` - Is there an array? What values does it contain?
  - `metadata.whatsapp_id` - Does this field exist? What's the value?
  - `original_name` - Does this field exist? What's the value?

**Expected behavior:**
- If your number exists in ANY of these fields, you should see console logs like:
  - "Found own number in chat name: ..."
  - "Found own number in participants: ..."
  - "Found own number in metadata: ..."
  - "Found own number in original_name: ..."

**If you don't see these logs:**
- Your number might not be in the system yet
- You may need to create a chat with yourself first
- The number format might be different than expected

**To create a chat with yourself:**
1. Click "Neuer Chat" button
2. Enter your number: `4915908115183` or `+4915908115183`
3. Send a message to yourself
4. The chat should then appear in the list

---

### 2. Unread Message Indicator Not Showing

**Console Output to Check:**
```
📖 Selecting chat: [ID] lastReadId from localStorage: [ID or null]
Sorted messages changed, count: [N] lastReadMessageId: [ID or null]
Last read message index: [N or -1]
First unread candidate: [ID] isFromMe: [true/false] content: [...]
✅ Unread indicator set for message: [ID]
```

**What each log means:**

1. **"📖 Selecting chat"** - Shows when you open a chat
   - If `lastReadId` is `null`, there's no stored last read message
   - This is normal for new chats or first-time opens

2. **"Sorted messages changed"** - Shows when messages are loaded
   - `count` should be > 0
   - `lastReadMessageId` should match the stored value

3. **"Last read message index"** - Position of last read message
   - `-1` means the message wasn't found (might be deleted or too old)
   - Should be a number between 0 and message count

4. **"First unread candidate"** - The message after the last read one
   - `isFromMe: true` means it's YOUR message (won't show indicator)
   - `isFromMe: false` means it's from someone else (WILL show indicator)

5. **"✅ Unread indicator set"** - Success! Indicator should appear

**Common issues:**

❌ **"No lastReadMessageId or no messages"**
- You haven't scrolled to the bottom of this chat before
- Solution: Scroll to bottom, close chat, reopen it

❌ **"No unread messages (lastReadIndex: -1)"**
- The last read message was deleted or is too old
- Solution: Scroll to bottom to update the marker

❌ **"First unread is from me, clearing indicator"**
- The next message after your last read is from YOU
- This is correct behavior - no indicator needed

**How to test:**
1. Open a chat and scroll to the very bottom
2. Close the chat (or switch to another chat)
3. Have someone send you a message (or send from another device)
4. Reopen the chat
5. You should see the green "Neue Nachrichten" divider

**Check localStorage:**
- Open DevTools (F12)
- Go to Application tab → Local Storage
- Look for keys like `lastRead_1`, `lastRead_2`, etc.
- These store the last message ID you saw in each chat

---

### 3. Dark Mode Too Blue

**Changes made:**
- Edit button: `dark:text-blue-400` → `dark:text-gray-400`
- Info notice background: `dark:bg-blue-900/20` → `dark:bg-gray-700`
- Info notice text: `dark:text-blue-200` → `dark:text-gray-300`
- Loading text: `dark:text-blue-400` → `dark:text-gray-400`

**If still too blue:**
- Check which specific elements look too blue
- Look for classes with `blue-` in dark mode
- We can change more colors to gray/green

---

### 4. Search Placeholder Brightness

**Changed:**
- `dark:placeholder-gray-500` → `dark:placeholder-gray-400`

This makes the placeholder text brighter (400 is lighter than 500 in Tailwind)

**If still too dark:**
- Can change to `dark:placeholder-gray-300` (even brighter)

---

## Quick Test Checklist

### Own Number Sorting:
- [ ] Open browser console (F12)
- [ ] Look for "Found own number in..." logs
- [ ] Check the chat structure in console
- [ ] Try creating a chat with your number if it doesn't exist

### Unread Indicator:
- [ ] Open a chat, scroll to bottom
- [ ] Check console for "📖 Selecting chat" log
- [ ] Close the chat
- [ ] Send yourself a message from another device
- [ ] Reopen the chat
- [ ] Check console for "✅ Unread indicator set" log
- [ ] Look for green "Neue Nachrichten" divider

### Dark Mode:
- [ ] Toggle dark mode on
- [ ] Check if colors are more neutral (less blue)
- [ ] Test search placeholder readability
- [ ] Check contact modal colors

---

## Next Steps

Based on the console output you see, we can:
1. Adjust the number format patterns if your number isn't being detected
2. Fix the unread indicator logic if the messages aren't being tracked correctly
3. Adjust more colors if dark mode is still too blue
4. Make the placeholder even brighter if needed

**Please share:**
1. The full chat object from console (expand it completely)
2. All console logs when opening a chat
3. Screenshots of any remaining blue elements in dark mode

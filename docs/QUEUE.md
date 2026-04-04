# Queue System - Quick Start Guide

Get the queue system running in 5 minutes!

## Step 1: Update Backend Configuration (1 minute)

Open `backend/.env` and change this line:

```env
# Change from:
QUEUE_CONNECTION=sync

# To:
QUEUE_CONNECTION=database
```

Save the file.

## Step 2: Clear Configuration Cache (30 seconds)

```bash
cd backend
php artisan config:clear
php artisan config:cache
```

## Step 3: Start Queue Worker (30 seconds)

Open a new terminal/PowerShell window and run:

```bash
cd backend
php artisan queue:work --queue=high,default,low --verbose
```

**Keep this terminal open!** This is your queue worker processing messages.

You should see:
```
[2025-10-21 12:44:00][1] Processing: App\Jobs\ProcessWhatsAppMessage
[2025-10-21 12:44:01][1] Processed:  App\Jobs\ProcessWhatsAppMessage
```

## Step 4: Test It! (2 minutes)

### Option A: Send a WhatsApp Message

1. Send a message to your WhatsApp bot
2. Watch the queue worker terminal - you should see it processing
3. Check your frontend - message should appear

### Option B: Run Load Test

```bash
# Install axios if not already installed
npm install axios

# Run a quick test
node load-test-queue.js --count 10 --type text
```

## That's It! 🎉

Your queue system is now running. Messages are processed asynchronously!

## What's Happening?

**Before (Synchronous):**
```
WhatsApp → Receiver → Backend → [WAIT] → Process → [WAIT] → Response
                                  (slow)            (slow)
```

**After (Queue):**
```
WhatsApp → Receiver → Backend → Queue → Response (fast!)
                                  ↓
                               Worker processes in background
```

## Next Steps

### For Development
You're all set! Just keep the queue worker running while developing.

### For Production

1. **Run multiple workers** for better performance:
   ```bash
   # Single worker processing all queues (recommended for most cases)
   php artisan queue:work --queue=high,default,low --tries=3 --verbose
   
   # OR run separate workers per queue (advanced - for high load)
   # Terminal 1 - High priority (text/reactions)
   php artisan queue:work --queue=high --tries=3 --verbose
   
   # Terminal 2 - Default priority (images/audio)
   php artisan queue:work --queue=default --tries=3 --verbose
   
   # Terminal 3 - Low priority (videos/documents)
   php artisan queue:work --queue=low --tries=3 --verbose
   ```

2. **Set up as Windows Service** (see `QUEUE_SYSTEM_SETUP.md`)

3. **Monitor the queue**:
   ```bash
   php artisan queue:monitor database:high,database:default,database:low
   ```

## Troubleshooting

### Worker Not Processing?

**Check 1:** Is the worker running?
```bash
# You should see output like:
[2025-10-21 12:44:00] Processing: App\Jobs\ProcessWhatsAppMessage
```

**Check 2:** Is QUEUE_CONNECTION set correctly?
```bash
php artisan config:show queue.default
# Should show: "database"
```

**Check 3:** Are jobs in the queue?
```bash
php artisan tinker
>>> DB::table('jobs')->count();
# Should show number of pending jobs
```

### Messages Not Appearing?

**Check backend logs:**
```bash
tail -f backend/storage/logs/laravel.log
```

**Check WhatsApp logs:**
```bash
tail -f backend/storage/logs/whatsapp.log
```

### Queue Backing Up?

Add more workers! Open additional terminals and run:
```bash
php artisan queue:work --verbose
```

## Monitoring Commands

```bash
# View pending jobs
php artisan queue:monitor

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear all jobs (use with caution!)
php artisan queue:clear
```

## Performance Tips

1. **Multiple workers = faster processing**
   - 1 worker: ~10 messages/second
   - 4 workers: ~40 messages/second
   - 8 workers: ~80 messages/second

2. **Priority queues = better UX**
   - Text messages (high priority) process first
   - Large media files (low priority) don't block text

3. **Database vs Redis**
   - Database: Good for <50 msg/s
   - Redis: Better for >50 msg/s

## Need More Help?

- **Full documentation:** See `QUEUE_SYSTEM_SETUP.md`
- **Load testing:** See `QUEUE_LOAD_TEST.md`
- **Laravel docs:** https://laravel.com/docs/queues

## Switching Back to Sync Mode

If you need to disable the queue (for debugging):

```env
# In backend/.env
QUEUE_CONNECTION=sync
```

Then restart:
```bash
php artisan config:clear
```

Messages will process synchronously again (slower but easier to debug).

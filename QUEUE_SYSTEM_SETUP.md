# Queue System Setup Guide

This guide explains how to configure and run the Laravel queue system for processing WhatsApp messages asynchronously.

## Overview

The queue system allows your WhatsApp Bot to handle multiple concurrent messages efficiently by:
- ✅ Processing messages asynchronously (non-blocking webhook responses)
- ✅ Automatic retry mechanism for failed messages
- ✅ Priority queues (text messages processed faster than large media files)
- ✅ Horizontal scaling with multiple workers
- ✅ Failed job tracking and monitoring

## Architecture

```
WhatsApp → Receiver (Node.js) → Backend Webhook → Queue → Workers → Database
                                      ↓ (200 OK)
                                   Immediate Response
```

**Message Flow:**
1. Receiver sends message to webhook
2. Webhook validates and dispatches job to queue (returns 200 OK immediately)
3. Queue workers process jobs asynchronously
4. Failed jobs are automatically retried (3 attempts with exponential backoff)

## Configuration

### Step 1: Update Backend .env File

Open `backend/.env` and update the following settings:

```env
# Queue Configuration
QUEUE_CONNECTION=database

# Optional: Database Queue Settings (if using database driver)
DB_QUEUE_CONNECTION=mysql
DB_QUEUE_TABLE=jobs
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=90
```

**Available Queue Drivers:**
- `sync` - Synchronous (no queue, for development/testing)
- `database` - Uses MySQL database (recommended for small-medium scale)
- `redis` - Uses Redis (recommended for high-performance production)
- `beanstalkd` - Uses Beanstalkd queue server
- `sqs` - AWS SQS (for cloud deployments)

**Recommendation:** Start with `database` driver. Switch to `redis` if you need higher throughput (>100 messages/second).

### Step 2: For Redis Queue (Optional - High Performance)

If you want to use Redis for better performance:

1. Install Redis on your server
2. Update `.env`:
```env
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_QUEUE=default
```

3. Install Redis PHP extension:
```bash
# Windows (via PECL or pre-compiled DLL)
# Download from: https://pecl.php.net/package/redis

# Linux
sudo apt-get install php-redis
```

## Running Queue Workers

### Development Mode

For local development, run a single worker in your terminal:

```bash
cd backend
php artisan queue:work --verbose
```

**Options:**
- `--verbose` - Show detailed output
- `--tries=3` - Number of retry attempts
- `--timeout=120` - Maximum seconds per job
- `--sleep=3` - Seconds to sleep when no jobs available
- `--queue=high,default,low` - Process queues by priority

### Production Mode (Windows)

#### Option 1: Multiple PowerShell Windows

Open 3 separate PowerShell windows and run:

**Window 1 - High Priority Queue (Text/Reactions):**
```powershell
cd backend
php artisan queue:work --queue=high --tries=3 --timeout=60
```

**Window 2 - Default Queue (Images/Audio):**
```powershell
cd backend
php artisan queue:work --queue=default --tries=3 --timeout=120
```

**Window 3 - Low Priority Queue (Videos/Documents):**
```powershell
cd backend
php artisan queue:work --queue=low --tries=3 --timeout=180
```

#### Option 2: Windows Service (Recommended for Production)

Create a Windows service using NSSM (Non-Sucking Service Manager):

1. Download NSSM: https://nssm.cc/download
2. Install as service:

```powershell
# Install NSSM service for queue worker
nssm install WhatsAppQueueWorker "C:\path\to\php.exe" "artisan queue:work --queue=high,default,low --tries=3 --sleep=3 --max-time=3600"
nssm set WhatsAppQueueWorker AppDirectory "C:\path\to\backend"
nssm set WhatsAppQueueWorker DisplayName "WhatsApp Bot Queue Worker"
nssm set WhatsAppQueueWorker Description "Processes WhatsApp messages asynchronously"
nssm set WhatsAppQueueWorker Start SERVICE_AUTO_START

# Start the service
nssm start WhatsAppQueueWorker
```

#### Option 3: Task Scheduler

Create a scheduled task that runs continuously:

1. Open Task Scheduler
2. Create Basic Task: "WhatsApp Queue Worker"
3. Trigger: At startup
4. Action: Start a program
   - Program: `php.exe`
   - Arguments: `artisan queue:work --queue=high,default,low --tries=3`
   - Start in: `C:\path\to\backend`
5. Settings:
   - ✅ Run whether user is logged on or not
   - ✅ Run with highest privileges
   - ✅ If task fails, restart every 1 minute

### Production Mode (Linux)

Use Supervisor to manage queue workers:

1. Install Supervisor:
```bash
sudo apt-get install supervisor
```

2. Create configuration file `/etc/supervisor/conf.d/whatsapp-worker.conf`:

```ini
[program:whatsapp-worker-high]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/backend/artisan queue:work --queue=high --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/whatsapp-worker-high.log
stopwaitsecs=3600

[program:whatsapp-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/backend/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/var/log/whatsapp-worker-default.log
stopwaitsecs=3600

[program:whatsapp-worker-low]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/backend/artisan queue:work --queue=low --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/whatsapp-worker-low.log
stopwaitsecs=3600
```

3. Start Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

## Queue Priority System

The system automatically assigns messages to different queues based on type:

| Queue | Message Types | Priority | Workers | Timeout |
|-------|--------------|----------|---------|---------|
| **high** | text, reaction | Highest | 2 | 60s |
| **default** | image, audio | Medium | 3 | 120s |
| **low** | video, document | Lowest | 2 | 180s |

This ensures text messages are processed quickly while large media files don't block the queue.

## Monitoring & Management

### View Queue Status

```bash
# Check pending jobs
php artisan queue:monitor

# List all jobs in queue
php artisan queue:work --once --verbose

# Check failed jobs
php artisan queue:failed
```

### Retry Failed Jobs

```bash
# Retry all failed jobs
php artisan queue:retry all

# Retry specific job by ID
php artisan queue:retry 5

# Retry jobs that failed in last hour
php artisan queue:retry --range=1-100
```

### Clear Queue

```bash
# Clear all jobs from queue
php artisan queue:clear

# Clear specific queue
php artisan queue:clear --queue=high

# Flush all failed jobs
php artisan queue:flush
```

### Monitor Queue Performance

```bash
# Real-time queue monitoring
php artisan queue:monitor database:high,database:default,database:low --max=100

# View queue statistics
php artisan queue:work --verbose --once
```

## Testing the Queue System

### Test 1: Single Message

1. Start queue worker:
```bash
php artisan queue:work --verbose
```

2. Send a test message via WhatsApp
3. Watch the worker output - you should see:
   - "Processing queued message"
   - "Successfully processed queued message"

### Test 2: Concurrent Messages

Use the load testing script (see `QUEUE_LOAD_TEST.md`) to send multiple messages simultaneously and verify they're processed correctly.

### Test 3: Failed Job Recovery

1. Temporarily break something (e.g., stop database)
2. Send a message
3. Job will fail and be retried automatically
4. Fix the issue
5. Job should process successfully on retry

## Performance Tuning

### Optimize Worker Count

**Rule of thumb:**
- **CPU-bound tasks:** Workers = CPU cores
- **I/O-bound tasks:** Workers = 2-4x CPU cores
- **Mixed workload:** Start with 2x CPU cores

Example for 4-core server:
- High priority: 2 workers
- Default priority: 4 workers  
- Low priority: 2 workers
- **Total: 8 workers**

### Optimize Database Queue

If using database driver, add indexes:

```sql
-- Already created by migration, but verify:
CREATE INDEX jobs_queue_index ON jobs(queue);
CREATE INDEX jobs_reserved_at_index ON jobs(reserved_at);
```

### Memory Management

Monitor memory usage:
```bash
# Set memory limit per worker
php artisan queue:work --memory=512
```

### Restart Workers Regularly

Workers should be restarted periodically to prevent memory leaks:

```bash
# Gracefully restart all workers (waits for current jobs to finish)
php artisan queue:restart
```

Add to cron (Linux) or Task Scheduler (Windows):
```bash
# Restart workers every hour
0 * * * * php /path/to/backend/artisan queue:restart
```

## Troubleshooting

### Workers Not Processing Jobs

**Check 1:** Verify queue connection
```bash
php artisan queue:work --verbose
# Should show: "Processing: App\Jobs\ProcessWhatsAppMessage"
```

**Check 2:** Verify .env configuration
```bash
php artisan config:clear
php artisan config:cache
```

**Check 3:** Check database connection
```bash
php artisan tinker
>>> DB::table('jobs')->count();
```

### Jobs Failing Repeatedly

**Check failed jobs table:**
```bash
php artisan queue:failed
```

**View specific failure:**
```bash
php artisan queue:failed
# Note the ID, then:
php artisan tinker
>>> DB::table('failed_jobs')->where('id', 1)->first();
```

### Queue Filling Up

**Symptoms:** Jobs table growing, workers can't keep up

**Solutions:**
1. Add more workers
2. Increase worker timeout
3. Optimize message processing code
4. Switch to Redis queue driver

### High Memory Usage

**Solutions:**
1. Reduce `--memory` limit
2. Add `--max-jobs=1000` to restart worker after processing 1000 jobs
3. Add `--max-time=3600` to restart worker after 1 hour

## Switching Between Sync and Queue

### Development (Sync Mode)
```env
QUEUE_CONNECTION=sync
```
Messages process immediately, easier to debug.

### Production (Queue Mode)
```env
QUEUE_CONNECTION=database
# or
QUEUE_CONNECTION=redis
```
Messages process asynchronously, better performance.

The system automatically detects the mode - no code changes needed!

## Security Considerations

1. **Queue workers run as system user** - Ensure proper file permissions
2. **Failed jobs contain message data** - Secure the `failed_jobs` table
3. **Queue monitoring** - Restrict access to queue management commands
4. **Log files** - Rotate and secure worker logs

## Next Steps

1. ✅ Update `backend/.env` with `QUEUE_CONNECTION=database`
2. ✅ Start queue workers (see "Running Queue Workers" section)
3. ✅ Test with a few messages
4. ✅ Run load test (see `QUEUE_LOAD_TEST.md`)
5. ✅ Monitor performance and adjust worker count
6. ✅ Set up production worker management (Supervisor/NSSM)

## Support

For issues or questions:
- Check Laravel Queue documentation: https://laravel.com/docs/queues
- Review logs: `backend/storage/logs/laravel.log`
- Check WhatsApp logs: `backend/storage/logs/whatsapp.log`

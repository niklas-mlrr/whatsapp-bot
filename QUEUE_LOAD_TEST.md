# Queue Load Testing Guide

This guide helps you test the queue system's ability to handle concurrent WhatsApp messages.

## Prerequisites

- Queue system configured (see `QUEUE_SYSTEM_SETUP.md`)
- Queue workers running
- Backend API accessible
- Node.js installed (for test script)

## Test Scenarios

### Scenario 1: Light Load (10 concurrent messages)
**Purpose:** Verify basic queue functionality  
**Expected:** All messages processed within 5 seconds

### Scenario 2: Medium Load (50 concurrent messages)
**Purpose:** Test typical busy period  
**Expected:** All messages processed within 15 seconds

### Scenario 3: Heavy Load (200 concurrent messages)
**Purpose:** Stress test the system  
**Expected:** All messages processed within 60 seconds

### Scenario 4: Mixed Message Types
**Purpose:** Test priority queue system  
**Expected:** Text messages processed faster than media

## Load Test Script

Save this as `load-test-queue.js` in the project root:

```javascript
const axios = require('axios');

// Configuration
const BACKEND_URL = 'http://localhost:8000/api/whatsapp-webhook';
const WEBHOOK_SECRET = 'your-webhook-secret-here'; // Update this!
const TEST_PHONE = '1234567890@s.whatsapp.net';

// Test message templates
const messageTemplates = {
    text: {
        sender: TEST_PHONE,
        chat: TEST_PHONE,
        type: 'text',
        content: 'Load test message',
        sending_time: new Date().toISOString(),
        messageId: null, // Will be generated
    },
    image: {
        sender: TEST_PHONE,
        chat: TEST_PHONE,
        type: 'image',
        content: 'Test image caption',
        media: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        mimetype: 'image/png',
        sending_time: new Date().toISOString(),
        messageId: null,
    },
    reaction: {
        sender: TEST_PHONE,
        chat: TEST_PHONE,
        type: 'reaction',
        reactedMessageId: 'test-message-id',
        emoji: '👍',
        senderJid: TEST_PHONE,
        sending_time: new Date().toISOString(),
        messageId: null,
    }
};

// Generate unique message ID
function generateMessageId() {
    return `TEST_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

// Send a single message
async function sendMessage(messageType, index) {
    const message = { ...messageTemplates[messageType] };
    message.messageId = generateMessageId();
    message.content = `${message.content || ''} #${index}`;

    const startTime = Date.now();
    
    try {
        const response = await axios.post(BACKEND_URL, message, {
            headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Secret': WEBHOOK_SECRET,
            },
            timeout: 5000, // 5 second timeout for webhook response
        });

        const responseTime = Date.now() - startTime;
        
        return {
            success: true,
            messageId: message.messageId,
            type: messageType,
            responseTime,
            status: response.status,
        };
    } catch (error) {
        const responseTime = Date.now() - startTime;
        
        return {
            success: false,
            messageId: message.messageId,
            type: messageType,
            responseTime,
            error: error.message,
        };
    }
}

// Run load test
async function runLoadTest(config) {
    console.log('\n' + '='.repeat(60));
    console.log(`Load Test: ${config.name}`);
    console.log('='.repeat(60));
    console.log(`Messages: ${config.count}`);
    console.log(`Types: ${config.types.join(', ')}`);
    console.log(`Concurrency: ${config.concurrent ? 'Yes' : 'No'}`);
    console.log('='.repeat(60) + '\n');

    const startTime = Date.now();
    const promises = [];
    const results = [];

    // Generate messages
    for (let i = 0; i < config.count; i++) {
        const messageType = config.types[i % config.types.length];
        
        if (config.concurrent) {
            // Send all at once
            promises.push(sendMessage(messageType, i + 1));
        } else {
            // Send sequentially
            const result = await sendMessage(messageType, i + 1);
            results.push(result);
            process.stdout.write(`\rSent: ${i + 1}/${config.count}`);
        }
    }

    // Wait for all concurrent requests
    if (config.concurrent) {
        console.log('Sending messages concurrently...');
        const concurrentResults = await Promise.all(promises);
        results.push(...concurrentResults);
    }

    const totalTime = Date.now() - startTime;

    // Calculate statistics
    const successful = results.filter(r => r.success).length;
    const failed = results.filter(r => !r.success).length;
    const avgResponseTime = results.reduce((sum, r) => sum + r.responseTime, 0) / results.length;
    const maxResponseTime = Math.max(...results.map(r => r.responseTime));
    const minResponseTime = Math.min(...results.map(r => r.responseTime));

    // Group by type
    const byType = {};
    config.types.forEach(type => {
        const typeResults = results.filter(r => r.type === type);
        byType[type] = {
            count: typeResults.length,
            successful: typeResults.filter(r => r.success).length,
            avgResponseTime: typeResults.reduce((sum, r) => sum + r.responseTime, 0) / typeResults.length,
        };
    });

    // Print results
    console.log('\n\n' + '='.repeat(60));
    console.log('RESULTS');
    console.log('='.repeat(60));
    console.log(`Total Time: ${(totalTime / 1000).toFixed(2)}s`);
    console.log(`Throughput: ${(config.count / (totalTime / 1000)).toFixed(2)} messages/second`);
    console.log(`\nWebhook Response Times:`);
    console.log(`  Average: ${avgResponseTime.toFixed(2)}ms`);
    console.log(`  Min: ${minResponseTime.toFixed(2)}ms`);
    console.log(`  Max: ${maxResponseTime.toFixed(2)}ms`);
    console.log(`\nSuccess Rate:`);
    console.log(`  Successful: ${successful} (${((successful / config.count) * 100).toFixed(1)}%)`);
    console.log(`  Failed: ${failed} (${((failed / config.count) * 100).toFixed(1)}%)`);
    
    console.log(`\nBy Message Type:`);
    Object.entries(byType).forEach(([type, stats]) => {
        console.log(`  ${type}:`);
        console.log(`    Count: ${stats.count}`);
        console.log(`    Success: ${stats.successful}/${stats.count}`);
        console.log(`    Avg Response: ${stats.avgResponseTime.toFixed(2)}ms`);
    });

    if (failed > 0) {
        console.log(`\nFailed Messages:`);
        results.filter(r => !r.success).forEach(r => {
            console.log(`  ${r.messageId}: ${r.error}`);
        });
    }

    console.log('='.repeat(60) + '\n');

    return {
        totalTime,
        successful,
        failed,
        avgResponseTime,
        throughput: config.count / (totalTime / 1000),
    };
}

// Test configurations
const tests = [
    {
        name: 'Light Load - Text Only',
        count: 10,
        types: ['text'],
        concurrent: true,
    },
    {
        name: 'Medium Load - Mixed Types',
        count: 50,
        types: ['text', 'text', 'text', 'image', 'reaction'],
        concurrent: true,
    },
    {
        name: 'Heavy Load - All Types',
        count: 200,
        types: ['text', 'text', 'image', 'reaction'],
        concurrent: true,
    },
    {
        name: 'Priority Test - Text vs Images',
        count: 30,
        types: ['text', 'image'],
        concurrent: true,
    },
];

// Run all tests
async function runAllTests() {
    console.log('\n');
    console.log('╔════════════════════════════════════════════════════════════╗');
    console.log('║         WhatsApp Queue System Load Test Suite             ║');
    console.log('╚════════════════════════════════════════════════════════════╝');
    console.log('\nIMPORTANT: Make sure queue workers are running!');
    console.log('Run: php artisan queue:work --verbose\n');
    
    await new Promise(resolve => setTimeout(resolve, 2000));

    const allResults = [];

    for (const test of tests) {
        const result = await runLoadTest(test);
        allResults.push({ name: test.name, ...result });
        
        // Wait between tests
        console.log('Waiting 5 seconds before next test...\n');
        await new Promise(resolve => setTimeout(resolve, 5000));
    }

    // Summary
    console.log('\n' + '='.repeat(60));
    console.log('SUMMARY - ALL TESTS');
    console.log('='.repeat(60));
    allResults.forEach(result => {
        console.log(`\n${result.name}:`);
        console.log(`  Time: ${(result.totalTime / 1000).toFixed(2)}s`);
        console.log(`  Success Rate: ${((result.successful / (result.successful + result.failed)) * 100).toFixed(1)}%`);
        console.log(`  Throughput: ${result.throughput.toFixed(2)} msg/s`);
        console.log(`  Avg Response: ${result.avgResponseTime.toFixed(2)}ms`);
    });
    console.log('='.repeat(60) + '\n');
}

// Parse command line arguments
const args = process.argv.slice(2);
if (args.includes('--help') || args.includes('-h')) {
    console.log(`
Usage: node load-test-queue.js [options]

Options:
  --count <n>      Number of messages to send (default: 10)
  --type <type>    Message type: text, image, reaction (default: text)
  --concurrent     Send all messages at once (default: true)
  --sequential     Send messages one by one
  --all            Run all test scenarios

Examples:
  node load-test-queue.js --count 50 --type text
  node load-test-queue.js --count 100 --concurrent
  node load-test-queue.js --all
    `);
    process.exit(0);
}

// Run tests
if (args.includes('--all')) {
    runAllTests().catch(console.error);
} else {
    // Single test with custom parameters
    const count = parseInt(args[args.indexOf('--count') + 1]) || 10;
    const type = args[args.indexOf('--type') + 1] || 'text';
    const concurrent = !args.includes('--sequential');

    runLoadTest({
        name: 'Custom Test',
        count,
        types: [type],
        concurrent,
    }).catch(console.error);
}
```

## How to Run Tests

### 1. Install Dependencies

```bash
npm install axios
```

### 2. Update Configuration

Edit `load-test-queue.js` and update:
- `BACKEND_URL` - Your backend webhook URL
- `WEBHOOK_SECRET` - Your webhook secret from `.env`
- `TEST_PHONE` - Test phone number

### 3. Start Queue Workers

```bash
cd backend
php artisan queue:work --verbose
```

### 4. Run Tests

**Run all test scenarios:**
```bash
node load-test-queue.js --all
```

**Run custom test:**
```bash
# 50 text messages concurrently
node load-test-queue.js --count 50 --type text --concurrent

# 100 images sequentially
node load-test-queue.js --count 100 --type image --sequential
```

## Monitoring During Tests

### Terminal 1: Queue Worker
```bash
php artisan queue:work --verbose
```
Watch messages being processed in real-time.

### Terminal 2: Queue Monitor
```bash
watch -n 1 "php artisan queue:monitor database:high,database:default,database:low"
```
Monitor queue depth and processing rate.

### Terminal 3: Database Monitor
```bash
watch -n 1 "mysql -u root -p -e 'SELECT queue, COUNT(*) as pending FROM whatsapp_bot.jobs GROUP BY queue'"
```
Watch pending jobs in database.

### Terminal 4: Load Test Script
```bash
node load-test-queue.js --all
```
Run the load tests.

## Expected Results

### Light Load (10 messages)
- ✅ Webhook responses: < 100ms average
- ✅ All messages processed: < 5 seconds
- ✅ Success rate: 100%
- ✅ Queue never backs up

### Medium Load (50 messages)
- ✅ Webhook responses: < 150ms average
- ✅ All messages processed: < 15 seconds
- ✅ Success rate: > 99%
- ✅ Queue clears within 30 seconds

### Heavy Load (200 messages)
- ✅ Webhook responses: < 200ms average
- ✅ All messages processed: < 60 seconds
- ✅ Success rate: > 98%
- ✅ Queue clears within 2 minutes

### Priority Test
- ✅ Text messages processed faster than images
- ✅ High priority queue empties first
- ✅ No starvation of low priority queue

## Troubleshooting

### Webhook Timeouts
**Symptom:** Many failed requests with timeout errors  
**Solution:** 
- Check backend is running
- Verify webhook URL is correct
- Check firewall/network issues

### Queue Backing Up
**Symptom:** Jobs table growing, workers can't keep up  
**Solution:**
- Add more workers
- Increase worker timeout
- Check for slow database queries

### Failed Jobs
**Symptom:** Jobs moving to failed_jobs table  
**Solution:**
```bash
# View failed jobs
php artisan queue:failed

# Check error messages
php artisan tinker
>>> DB::table('failed_jobs')->latest()->first();

# Retry after fixing issue
php artisan queue:retry all
```

### Memory Issues
**Symptom:** Workers crashing during test  
**Solution:**
```bash
# Reduce memory per worker
php artisan queue:work --memory=256

# Restart workers more frequently
php artisan queue:work --max-jobs=100
```

## Performance Benchmarks

Based on typical hardware:

| Hardware | Workers | Throughput | Notes |
|----------|---------|------------|-------|
| 2 Core / 4GB RAM | 4 | ~20 msg/s | Development |
| 4 Core / 8GB RAM | 8 | ~50 msg/s | Small production |
| 8 Core / 16GB RAM | 16 | ~100 msg/s | Medium production |
| 16 Core / 32GB RAM | 32 | ~200 msg/s | Large production |

*With database queue. Redis can handle 2-3x more.*

## Next Steps

1. ✅ Run light load test to verify basic functionality
2. ✅ Gradually increase load to find your system's limits
3. ✅ Adjust worker count based on results
4. ✅ Consider switching to Redis if database queue is bottleneck
5. ✅ Set up monitoring and alerting for production

## Production Monitoring

After load testing, set up monitoring:

```bash
# Create monitoring script
cat > monitor-queue.sh << 'EOF'
#!/bin/bash
while true; do
    clear
    echo "=== Queue Status ==="
    php artisan queue:monitor database:high,database:default,database:low --max=100
    echo ""
    echo "=== Failed Jobs ==="
    php artisan queue:failed | head -10
    sleep 5
done
EOF

chmod +x monitor-queue.sh
./monitor-queue.sh
```

## Support

If you encounter issues:
1. Check worker logs: `backend/storage/logs/laravel.log`
2. Check WhatsApp logs: `backend/storage/logs/whatsapp.log`
3. Verify queue configuration: `php artisan config:show queue`
4. Test database connection: `php artisan tinker` → `DB::connection()->getPdo();`

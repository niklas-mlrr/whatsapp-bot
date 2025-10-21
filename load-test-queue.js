const axios = require('axios');

// Configuration
const BACKEND_URL = process.env.BACKEND_URL || 'http://localhost:8000/api/whatsapp-webhook';
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET || 'your-webhook-secret-here';
const TEST_PHONE = process.env.TEST_PHONE || '1234567890@s.whatsapp.net';

// Test message templates
const messageTemplates = {
    text: {
        sender: TEST_PHONE,
        chat: TEST_PHONE,
        type: 'text',
        content: 'Load test message',
        sending_time: new Date().toISOString(),
        messageId: null,
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
            timeout: 5000,
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
            promises.push(sendMessage(messageType, i + 1));
        } else {
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
        if (typeResults.length > 0) {
            byType[type] = {
                count: typeResults.length,
                successful: typeResults.filter(r => r.success).length,
                avgResponseTime: typeResults.reduce((sum, r) => sum + r.responseTime, 0) / typeResults.length,
            };
        }
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
        results.filter(r => !r.success).slice(0, 10).forEach(r => {
            console.log(`  ${r.messageId}: ${r.error}`);
        });
        if (failed > 10) {
            console.log(`  ... and ${failed - 10} more`);
        }
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
    console.log('\nConfiguration:');
    console.log(`  Backend URL: ${BACKEND_URL}`);
    console.log(`  Test Phone: ${TEST_PHONE}`);
    console.log(`  Webhook Secret: ${WEBHOOK_SECRET === 'your-webhook-secret-here' ? '⚠️  NOT CONFIGURED' : '✓ Configured'}`);
    console.log('\nIMPORTANT: Make sure queue workers are running!');
    console.log('Run: php artisan queue:work --verbose\n');
    
    if (WEBHOOK_SECRET === 'your-webhook-secret-here') {
        console.log('⚠️  WARNING: Please set WEBHOOK_SECRET environment variable or update the script!\n');
    }
    
    await new Promise(resolve => setTimeout(resolve, 3000));

    const allResults = [];

    for (const test of tests) {
        const result = await runLoadTest(test);
        allResults.push({ name: test.name, ...result });
        
        console.log('Waiting 5 seconds before next test...\n');
        await new Promise(resolve => setTimeout(resolve, 5000));
    }

    // Summary
    console.log('\n' + '='.repeat(60));
    console.log('SUMMARY - ALL TESTS');
    console.log('='.repeat(60));
    allResults.forEach(result => {
        const successRate = ((result.successful / (result.successful + result.failed)) * 100).toFixed(1);
        const status = successRate >= 99 ? '✓' : successRate >= 95 ? '⚠' : '✗';
        console.log(`\n${status} ${result.name}:`);
        console.log(`  Time: ${(result.totalTime / 1000).toFixed(2)}s`);
        console.log(`  Success Rate: ${successRate}%`);
        console.log(`  Throughput: ${result.throughput.toFixed(2)} msg/s`);
        console.log(`  Avg Response: ${result.avgResponseTime.toFixed(2)}ms`);
    });
    console.log('='.repeat(60) + '\n');
}

// Parse command line arguments
const args = process.argv.slice(2);
if (args.includes('--help') || args.includes('-h')) {
    console.log(`
WhatsApp Queue Load Testing Tool

Usage: node load-test-queue.js [options]

Options:
  --count <n>      Number of messages to send (default: 10)
  --type <type>    Message type: text, image, reaction (default: text)
  --concurrent     Send all messages at once (default: true)
  --sequential     Send messages one by one
  --all            Run all test scenarios

Environment Variables:
  BACKEND_URL      Backend webhook URL (default: http://localhost:8000/api/whatsapp-webhook)
  WEBHOOK_SECRET   Webhook secret for authentication
  TEST_PHONE       Test phone number (default: 1234567890@s.whatsapp.net)

Examples:
  node load-test-queue.js --count 50 --type text
  node load-test-queue.js --count 100 --concurrent
  node load-test-queue.js --all
  
  WEBHOOK_SECRET=mysecret node load-test-queue.js --all
    `);
    process.exit(0);
}

// Run tests
if (args.includes('--all')) {
    runAllTests().catch(error => {
        console.error('\n❌ Test suite failed:', error.message);
        process.exit(1);
    });
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
    }).catch(error => {
        console.error('\n❌ Test failed:', error.message);
        process.exit(1);
    });
}

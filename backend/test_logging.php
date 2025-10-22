<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Test Laravel logging
\Illuminate\Support\Facades\Log::info('Test Laravel log entry - checking year/month folder structure');
\Illuminate\Support\Facades\Log::error('Test Laravel error entry');

// Test WhatsApp logging
\Illuminate\Support\Facades\Log::channel('whatsapp')->info('Test WhatsApp log entry - checking year/month folder structure');
\Illuminate\Support\Facades\Log::channel('whatsapp')->debug('Test WhatsApp debug entry');

echo "Logging test completed!\n";
echo "Check storage/logs/[YEAR]/[MONTH]/ for the log files.\n";
echo "Expected structure:\n";
echo "  storage/logs/2025/10/laravel-22.log\n";
echo "  storage/logs/2025/10/whatsapp-22.log\n";

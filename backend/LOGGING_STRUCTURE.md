# Logging Structure Documentation

## Overview
Both WhatsApp and Laravel logs are now organized in a year/month folder structure for better scalability.

## Folder Structure
```
storage/logs/
├── 2025/
│   ├── 10/
│   │   ├── laravel-22.log
│   │   ├── whatsapp-22.log
│   │   ├── laravel-23.log
│   │   └── whatsapp-23.log
│   └── 11/
│       ├── laravel-01.log
│       └── whatsapp-01.log
└── 2026/
    └── 01/
        ├── laravel-15.log
        └── whatsapp-15.log
```

## File Naming Convention
- **Format**: `{prefix}-{day}.log`
- **Examples**: 
  - `laravel-22.log` (Laravel log for the 22nd day)
  - `whatsapp-15.log` (WhatsApp log for the 15th day)

The year and month are represented by the folder structure, so only the day number is included in the filename.

## Configuration

### Logging Channels
Both channels are configured in `config/logging.php`:

```php
'daily' => [
    'driver' => 'custom',
    'via' => \App\Logging\CreateDailyWithFoldersLogger::class,
    'name' => 'laravel',
    'base_path' => storage_path('logs'),
    'filename' => 'laravel',
    'level' => env('LOG_LEVEL', 'error'),
    'replace_placeholders' => true,
],

'whatsapp' => [
    'driver' => 'custom',
    'via' => \App\Logging\CreateDailyWithFoldersLogger::class,
    'name' => 'whatsapp',
    'base_path' => storage_path('logs'),
    'filename' => 'whatsapp',
    'level' => 'debug',
    'replace_placeholders' => true,
],
```

### Custom Handler
The `DailyWithFoldersHandler` class automatically:
1. Creates year/month folders as needed
2. Generates daily log files with the day number
3. Switches to a new file when the date changes

## Usage

### Laravel Logs
```php
use Illuminate\Support\Facades\Log;

Log::info('This goes to storage/logs/YYYY/MM/laravel-DD.log');
Log::error('Error message');
```

### WhatsApp Logs
```php
use Illuminate\Support\Facades\Log;

Log::channel('whatsapp')->info('WhatsApp message received');
Log::channel('whatsapp')->debug('Debug information');
```

## Maintenance

### Cleanup Old Logs
To move old log files from the root logs directory to the new structure:
```bash
php cleanup_old_logs.php
```

### Testing
To test the logging setup:
```bash
php test_logging.php
```

## Benefits
1. **Scalability**: Logs are organized by year and month, preventing a single directory from becoming too large
2. **Easy Navigation**: Find logs quickly by navigating to the specific year/month folder
3. **Automatic Rotation**: New files are created daily with minimal filename clutter
4. **Consistent Structure**: Both Laravel and WhatsApp logs follow the same organizational pattern

<?php

namespace App\Logging;

use Monolog\Logger;

class CreateDailyWithFoldersLogger
{
    /**
     * Create a custom Monolog instance.
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger($config['name'] ?? 'daily-with-folders');
        
        $basePath = $config['base_path'] ?? storage_path('logs');
        $filename = $config['filename'] ?? 'laravel.log';
        $level = Logger::toMonologLevel($config['level'] ?? 'debug');
        
        $handler = new DailyWithFoldersHandler($basePath, $filename, $level);
        
        $logger->pushHandler($handler);
        
        return $logger;
    }
}

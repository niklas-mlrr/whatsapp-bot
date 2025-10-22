<?php

namespace App\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class DailyWithFoldersHandler extends StreamHandler
{
    protected string $basePath;
    protected string $filename;
    protected ?string $currentPath = null;

    public function __construct(string $basePath, string $filename, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->filename = $filename;
        
        // Initialize with current date path
        $this->updatePath();
        
        parent::__construct($this->currentPath, $level, $bubble);
    }

    protected function updatePath(): void
    {
        $year = date('Y');
        $month = date('m');
        
        $directory = $this->basePath . '/' . $year . '/' . $month;
        
        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $this->currentPath = $directory . '/' . $this->filename;
    }

    public function handle(LogRecord $record): bool
    {
        // Update path in case the date has changed
        $this->updatePath();
        
        // Update the stream URL if it changed
        if ($this->url !== $this->currentPath) {
            $this->close();
            $this->url = $this->currentPath;
        }
        
        return parent::handle($record);
    }
}

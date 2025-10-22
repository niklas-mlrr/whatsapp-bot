<?php

namespace App\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class DailyWithFoldersHandler extends StreamHandler
{
    protected string $basePath;
    protected string $filenamePrefix;
    protected ?string $currentPath = null;
    protected ?string $currentDate = null;

    public function __construct(string $basePath, string $filenamePrefix, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->filenamePrefix = $filenamePrefix;
        
        // Initialize with current date path
        $this->updatePath();
        
        parent::__construct($this->currentPath, $level, $bubble);
    }

    protected function updatePath(): void
    {
        $currentDate = date('Y-m-d');
        
        // Only update if date has changed
        if ($this->currentDate === $currentDate && $this->currentPath !== null) {
            return;
        }
        
        $this->currentDate = $currentDate;
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        
        $directory = $this->basePath . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month;
        
        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        // Generate filename with only the day (year/month are in folder structure)
        $filename = $this->filenamePrefix . '-' . $day . '.log';
        $this->currentPath = $directory . DIRECTORY_SEPARATOR . $filename;
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

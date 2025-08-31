<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Set memory limit from environment
        $memoryLimit = env('MEMORY_LIMIT', '1024M');
        ini_set('memory_limit', $memoryLimit);
        
        // Optimize logging to prevent memory issues
        if (app()->environment('local')) {
            // In local environment, limit log level to prevent excessive logging
            config(['logging.channels.single.level' => 'error']);
            config(['logging.channels.daily.level' => 'error']);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fix for MySQL key length issue
        Schema::defaultStringLength(191);
        
        // Additional memory optimization
        if (function_exists('gc_enable')) {
            gc_enable();
        }
        
        // Set garbage collection threshold
        if (function_exists('gc_threshold')) {
            gc_threshold(1000);
        }
    }
}

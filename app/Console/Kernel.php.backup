<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        
        // Expire offers every hour
        $schedule->command('offers:expire')->hourly();
        
        // Process payments every 30 minutes
        $schedule->command('payments:process')->everyThirtyMinutes();
        
        // Process withdrawals every hour
        $schedule->command('withdrawals:process')->hourly();
        
        // Check for expired messages every 15 minutes
        $schedule->command('messages:check')->everyFifteenMinutes();
        
        // Check timeline deadlines every hour
        $schedule->command('milestones:check-deadlines')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

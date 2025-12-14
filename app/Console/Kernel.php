<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {

        $schedule->command('offers:expire')->hourly();

        $schedule->command('payments:process')->everyThirtyMinutes();

        $schedule->command('withdrawals:process')->hourly();

        $schedule->command('messages:check')->everyFifteenMinutes();

        $schedule->command('milestones:check-deadlines')->hourly();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

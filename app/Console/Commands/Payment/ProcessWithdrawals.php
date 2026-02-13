<?php

declare(strict_types=1);

namespace App\Console\Commands\Payment;

use App\Models\Payment\Withdrawal;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessWithdrawals extends Command
{
    protected $signature = 'withdrawals:process';

    protected $description = 'Process pending withdrawal requests';

    public function handle()
    {
        $this->info('Starting withdrawal processing...');

        $pendingWithdrawals = Withdrawal::where('status', 'pending')->get();

        $processed = 0;
        $failed = 0;

        foreach ($pendingWithdrawals as $withdrawal) {
            $this->processWithdrawalRequest($withdrawal, $processed, $failed);
        }

        $this->info("Withdrawal processing completed. Processed: {$processed}, Failed: {$failed}");

        return 0;
    }

    private function processWithdrawalRequest(Withdrawal $withdrawal, int &$processed, int &$failed): void
    {
        try {
            if ($withdrawal->process()) {
                ++$processed;
                $this->info("Withdrawal {$withdrawal->getKey()} processed successfully.");
            } else {
                ++$failed;
                $this->error("Withdrawal {$withdrawal->getKey()} failed to process.");
            }
        } catch (Exception $e) {
            ++$failed;
            $this->error("Withdrawal {$withdrawal->getKey()} failed with error: " . $e->getMessage());

            Log::error('Withdrawal processing error', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

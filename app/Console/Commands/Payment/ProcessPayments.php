<?php

declare(strict_types=1);

namespace App\Console\Commands\Payment;

use App\Models\Payment\JobPayment;
use Exception;
use Illuminate\Console\Command;
use Log;

class ProcessPayments extends Command
{
    protected $signature = 'payments:process';

    protected $description = 'Process pending job payments';

    public function handle()
    {
        $this->info('Starting payment processing...');

        $pendingPayments = JobPayment::where('status', 'pending')->get();

        $processed = 0;
        $failed = 0;

        foreach ($pendingPayments as $payment) {
            try {
                if ($payment->process()) {
                    ++$processed;
                    $this->info("Payment {$payment->getKey()} processed successfully.");
                } else {
                    ++$failed;
                    $this->error("Payment {$payment->getKey()} failed to process.");
                }
            } catch (Exception $e) {
                ++$failed;
                $this->error("Payment {$payment->getKey()} failed with error: " . $e->getMessage());

                Log::error('Payment processing error', [
                    'payment_id' => $payment->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Payment processing completed. Processed: {$processed}, Failed: {$failed}");

        return 0;
    }
}

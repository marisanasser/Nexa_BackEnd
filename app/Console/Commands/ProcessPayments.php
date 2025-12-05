<?php

namespace App\Console\Commands;

use App\Models\JobPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
                    $processed++;
                    $this->info("Payment {$payment->id} processed successfully.");
                } else {
                    $failed++;
                    $this->error("Payment {$payment->id} failed to process.");
                }
            } catch (\Exception $e) {
                $failed++;
                $this->error("Payment {$payment->id} failed with error: " . $e->getMessage());
                
                Log::error('Payment processing error', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Payment processing completed. Processed: {$processed}, Failed: {$failed}");

        return 0;
    }
} 
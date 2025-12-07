<?php

namespace App\Console\Commands;

use App\Models\Contract;
use Illuminate\Console\Command;

class UpdateContractReviewStatus extends Command
{
    
    protected $signature = 'contracts:update-review-status';

    
    protected $description = 'Update review status for all existing contracts';

    
    public function handle()
    {
        $this->info('Updating contract review status...');

        $contracts = Contract::all();
        $updated = 0;

        foreach ($contracts as $contract) {
            $contract->updateReviewStatus();
            $updated++;
            
            if ($updated % 10 === 0) {
                $this->info("Updated {$updated} contracts...");
            }
        }

        $this->info("Successfully updated {$updated} contracts!");
        
        return 0;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SetupStorageDirectories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:setup-directories';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create all necessary storage directories if they do not exist';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Setting up storage directories...');
        $this->newLine();

        $directories = [
            storage_path('app/public'),
            storage_path('framework/cache'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/testing'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        $created = 0;
        $exists = 0;

        foreach ($directories as $directory) {
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
                $this->line("✓ Created: {$directory}");
                $created++;
            } else {
                $this->line("• Exists: {$directory}");
                $exists++;
            }
        }

        $this->newLine();
        
        if ($created > 0) {
            $this->info("✓ Successfully created {$created} directory(ies)");
        }
        
        if ($exists > 0) {
            $this->info("• {$exists} directory(ies) already exist");
        }

        $this->newLine();
        $this->info('Storage directories setup complete!');

        return 0;
    }
}


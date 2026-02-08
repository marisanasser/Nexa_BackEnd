<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Models\User\User;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestEmail extends Command
{
    protected $signature = 'email:test {email} {--provider=all}';

    protected $description = 'Test email configuration with different providers';

    public function handle(): void
    {
        $email = $this->argument('email');
        $provider = $this->option('provider');

        $this->info("Testing email configuration for: {$email}");
        $this->info("Provider: {$provider}");
        $this->newLine();

        $user = new User();
        $user->email = $email;
        $user->name = 'Test User';

        if ('all' === $provider || 'ses' === $provider) {
            $this->testSES($user);
        }

        if ('all' === $provider || 'smtp' === $provider) {
            $this->testSMTP($user);
        }

        if ('all' === $provider || 'log' === $provider) {
            $this->testLog($user);
        }

        $this->info('Email testing completed. Check logs for details.');
    }

    private function testSES(User $user): void
    {
        $this->info('Testing AWS SES...');

        try {
            // Configure SES mailer
            config(['mail.default' => 'ses']);

            Mail::raw('This is a test email from Nexa Platform via AWS SES', function ($message) use ($user): void {
                $message->to($user->email)
                    ->subject('Test Email (SES) - Nexa Platform')
                ;
            });

            $this->info('✅ SES test successful');
        } catch (Exception $e) {
            $this->error('❌ SES test error: '.$e->getMessage());
        }

        $this->newLine();
    }

    private function testSMTP(User $user): void
    {
        $this->info('Testing SMTP...');

        try {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => env('MAIL_HOST', 'smtp.gmail.com'),
                'mail.mailers.smtp.port' => env('MAIL_PORT', 587),
                'mail.mailers.smtp.encryption' => env('MAIL_ENCRYPTION', 'tls'),
                'mail.mailers.smtp.username' => env('MAIL_USERNAME'),
                'mail.mailers.smtp.password' => env('MAIL_PASSWORD'),
                'mail.from.address' => env('MAIL_FROM_ADDRESS', 'no-reply@nexacreators.com.br'),
                'mail.from.name' => env('MAIL_FROM_NAME', 'Nexa'),
            ]);

            Mail::raw('This is a test email from Nexa Platform via SMTP', function ($message) use ($user): void {
                $message->to($user->email)
                    ->subject('Test Email (SMTP) - Nexa Platform')
                ;
            });

            $this->info('✅ SMTP test successful');
        } catch (Exception $e) {
            $this->error('❌ SMTP test error: '.$e->getMessage());
        }

        $this->newLine();
    }

    private function testLog(User $user): void
    {
        $this->info('Testing Log...');

        try {
            // Configure log mailer for testing
            config(['mail.default' => 'log']);

            Mail::raw('This is a test email from Nexa Platform via Log driver', function ($message) use ($user): void {
                $message->to($user->email)
                    ->subject('Test Email (Log) - Nexa Platform')
                ;
            });

            $this->info('✅ Log test successful - Check storage/logs/laravel.log for email content');
        } catch (Exception $e) {
            $this->error('❌ Log test error: '.$e->getMessage());
        }

        $this->newLine();
    }
}

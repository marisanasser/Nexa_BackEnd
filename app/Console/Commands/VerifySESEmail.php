<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Aws\Ses\SesClient;
use Exception;

class VerifySESEmail extends Command
{
    
    protected $signature = 'ses:verify {email} {--sender}';

    
    protected $description = 'Verify an email address in AWS SES';

    
    public function handle()
    {
        $email = $this->argument('email');
        $isSender = $this->option('sender');

        $this->info("Verifying email in AWS SES: {$email}");
        $this->info("Type: " . ($isSender ? 'Sender' : 'Recipient'));
        $this->newLine();

        if (!$this->isSESConfigured()) {
            $this->error('❌ AWS SES is not properly configured');
            $this->error('Please check your AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and AWS_DEFAULT_REGION environment variables');
            return 1;
        }

        try {
            $ses = new SesClient([
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
                'credentials' => [
                    'key' => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);

            
            $status = $this->getEmailVerificationStatus($ses, $email);
            $this->info("Current verification status: {$status}");

            if ($status === 'Success') {
                $this->info('✅ Email is already verified');
                return 0;
            }

            if ($status === 'Pending') {
                $this->info('⏳ Email verification is pending. Please check your email and click the verification link.');
                return 0;
            }

            
            $this->info('Sending verification email...');
            $ses->verifyEmailIdentity(['EmailAddress' => $email]);
            
            $this->info('✅ Verification email sent successfully!');
            $this->info('Please check your email and click the verification link.');
            $this->newLine();
            
            if ($isSender) {
                $this->info('💡 Sender email verification is required to send emails via SES.');
                $this->info('Once verified, you can send emails to verified recipient emails.');
            } else {
                $this->info('💡 Recipient email verification is required in SES sandbox mode.');
                $this->info('In production mode, you can send to any email address.');
            }
            
            $this->newLine();
            $this->info('To check verification status, run:');
            $this->info("php artisan ses:status {$email}");

        } catch (Exception $e) {
            $this->error('❌ Failed to verify email: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    
    private function isSESConfigured(): bool
    {
        return !empty(env('AWS_ACCESS_KEY_ID')) && 
               !empty(env('AWS_SECRET_ACCESS_KEY')) && 
               !empty(env('AWS_DEFAULT_REGION'));
    }

    
    private function getEmailVerificationStatus(SesClient $ses, string $email): string
    {
        try {
            $result = $ses->getIdentityVerificationAttributes(['Identities' => [$email]]);
            
            if (isset($result['VerificationAttributes'][$email]['VerificationStatus'])) {
                return $result['VerificationAttributes'][$email]['VerificationStatus'];
            }
            
            return 'NotVerified';
        } catch (Exception $e) {
            $this->error('Failed to get verification status: ' . $e->getMessage());
            return 'Error';
        }
    }
} 
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Aws\Ses\SesClient;
use Exception;

class CheckSESStatus extends Command
{
    
    protected $signature = 'ses:status {email?}';

    
    protected $description = 'Check verification status of emails in AWS SES';

    
    public function handle()
    {
        $email = $this->argument('email');

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

            if ($email) {
                $this->checkSingleEmail($ses, $email);
            } else {
                $this->checkAllEmails($ses);
            }

        } catch (Exception $e) {
            $this->error('❌ Failed to check SES status: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    
    private function checkSingleEmail(SesClient $ses, string $email)
    {
        $this->info("Checking status for: {$email}");
        $this->newLine();

        try {
            $result = $ses->getIdentityVerificationAttributes(['Identities' => [$email]]);
            
            if (isset($result['VerificationAttributes'][$email])) {
                $attributes = $result['VerificationAttributes'][$email];
                $status = $attributes['VerificationStatus'] ?? 'Unknown';
                
                $this->info("📧 Email: {$email}");
                $this->info("✅ Status: {$status}");
                
                if (isset($attributes['VerificationToken'])) {
                    $this->info("🔑 Verification Token: {$attributes['VerificationToken']}");
                }
                
                if (isset($attributes['VerificationTimestamp'])) {
                    $timestamp = date('Y-m-d H:i:s', strtotime($attributes['VerificationTimestamp']));
                    $this->info("⏰ Verified At: {$timestamp}");
                }
                
                $this->newLine();
                
                switch ($status) {
                    case 'Success':
                        $this->info('🎉 This email is verified and ready to use!');
                        break;
                    case 'Pending':
                        $this->info('⏳ Verification is pending. Check your email for the verification link.');
                        break;
                    case 'NotVerified':
                        $this->info('❌ Email is not verified. Run: php artisan ses:verify ' . $email);
                        break;
                    default:
                        $this->info('❓ Unknown status. Please check AWS SES console.');
                }
            } else {
                $this->error("Email {$email} not found in SES");
            }
            
        } catch (Exception $e) {
            $this->error("Failed to check email {$email}: " . $e->getMessage());
        }
    }

    
    private function checkAllEmails(SesClient $ses)
    {
        $this->info('Checking all email identities in SES...');
        $this->newLine();

        try {
            $result = $ses->listIdentities(['IdentityType' => 'EmailAddress']);
            
            if (empty($result['Identities'])) {
                $this->info('No email identities found in SES');
                return;
            }

            $this->info('Found ' . count($result['Identities']) . ' email identities:');
            $this->newLine();

            foreach ($result['Identities'] as $identity) {
                $this->checkSingleEmail($ses, $identity);
                $this->newLine();
            }

        } catch (Exception $e) {
            $this->error('Failed to list identities: ' . $e->getMessage());
        }
    }

    
    private function isSESConfigured(): bool
    {
        return !empty(env('AWS_ACCESS_KEY_ID')) && 
               !empty(env('AWS_SECRET_ACCESS_KEY')) && 
               !empty(env('AWS_DEFAULT_REGION'));
    }
} 
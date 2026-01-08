<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Models\Campaign\Bid;
use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignApplication;
use App\Models\Common\Notification;
use App\Models\User\User;
use Exception;
use Log;

class AdminNotificationService
{
    public static function notifyAdminOfNewLogin(User $user, array $loginData = []): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createLoginDetected($admin->id, array_merge($loginData, [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'user_role' => $user->role,
                ]));

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of new login', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfNewRegistration(User $user): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createNewUserRegistration($admin->id, [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'user_role' => $user->role,
                    'registration_time' => now()->toISOString(),
                ]);

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of new registration', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfNewCampaign(Campaign $campaign): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createNewCampaign($admin->id, [
                    'campaign_id' => $campaign->id,
                    'campaign_title' => $campaign->title,
                    'brand_id' => $campaign->brand_id,
                    'brand_name' => $campaign->brand->name,
                    'brand_email' => $campaign->brand->email,
                    'budget' => $campaign->budget,
                    'category' => $campaign->category,
                    'campaign_type' => $campaign->campaign_type,
                    'created_at' => $campaign->created_at->toISOString(),
                ]);

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of new campaign', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfNewApplication(CampaignApplication $application): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createNewApplication($admin->id, [
                    'application_id' => $application->id,
                    'campaign_id' => $application->campaign_id,
                    'campaign_title' => $application->campaign->title,
                    'creator_id' => $application->creator_id,
                    'creator_name' => $application->creator->name,
                    'creator_email' => $application->creator->email,
                    'brand_id' => $application->campaign->brand_id,
                    'brand_name' => $application->campaign->brand->name,
                    'proposal_amount' => $application->proposal_amount,
                    'created_at' => $application->created_at->toISOString(),
                ]);

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of new application', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfNewBid(Bid $bid): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createNewBid($admin->id, [
                    'bid_id' => $bid->id,
                    'campaign_id' => $bid->campaign_id,
                    'campaign_title' => $bid->campaign->title,
                    'creator_id' => $bid->user_id,
                    'creator_name' => $bid->user->name,
                    'creator_email' => $bid->user->email,
                    'brand_id' => $bid->campaign->brand_id,
                    'brand_name' => $bid->campaign->brand->name,
                    'bid_amount' => $bid->amount,
                    'created_at' => $bid->created_at->toISOString(),
                ]);

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of new bid', [
                'bid_id' => $bid->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfPaymentActivity(User $user, string $paymentType, array $paymentData = []): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createPaymentActivity($admin->id, array_merge($paymentData, [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'user_role' => $user->role,
                    'payment_type' => $paymentType,
                    'activity_time' => now()->toISOString(),
                ]));

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of payment activity', [
                'user_id' => $user->id,
                'payment_type' => $paymentType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfPortfolioUpdate(User $user, string $updateType, array $updateData = []): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createPortfolioUpdate($admin->id, array_merge($updateData, [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'update_type' => $updateType,
                    'update_time' => now()->toISOString(),
                ]));

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of portfolio update', [
                'user_id' => $user->id,
                'update_type' => $updateType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfSystemActivity(string $activityType, array $activityData = []): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createSystemActivity($admin->id, array_merge($activityData, [
                    'activity_type' => $activityType,
                    'activity_time' => now()->toISOString(),
                ]));

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of system activity', [
                'activity_type' => $activityType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfNewStudentVerification(User $user, array $studentData = []): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::createSystemActivity($admin->id, array_merge($studentData, [
                    'activity_type' => 'student_verification',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'student_verification_time' => now()->toISOString(),
                ]));

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of new student verification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfContractDispute($contract, ?string $reason = null): void
    {
        try {
            $adminUsers = User::where('role', 'admin')->get();

            foreach ($adminUsers as $admin) {
                $notification = Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'contract_dispute',
                    'title' => 'Disputa de Contrato',
                    'message' => "O contrato '{$contract->title}' entrou em disputa." . ($reason ? " Motivo: {$reason}" : ''),
                    'data' => [
                        'contract_id' => $contract->id,
                        'contract_title' => $contract->title,
                        'brand_id' => $contract->brand_id,
                        'creator_id' => $contract->creator_id,
                        'reason' => $reason,
                        'disputed_at' => now()->toISOString(),
                    ],
                    'is_read' => false,
                ]);

                NotificationService::sendSocketNotification($admin->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify admin of contract dispute', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

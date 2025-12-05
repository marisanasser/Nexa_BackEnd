<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Bid;
use App\Models\Message;
use App\Models\DirectMessage;
use App\Models\CampaignApplication;
use App\Models\Portfolio;
use App\Models\PortfolioItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of new login', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of new registration', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of new campaign', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyBrandOfCampaignCreated(Campaign $campaign): void
    {
        try {
            $campaign->load(['brand']);
            
            
            Mail::to($campaign->brand->email)->send(new \App\Mail\CampaignCreated($campaign));
            
            Log::info('Campaign creation email sent successfully', [
                'campaign_id' => $campaign->id,
                'brand_email' => $campaign->brand->email
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send campaign creation email', [
                'campaign_id' => $campaign->id,
                'brand_email' => $campaign->brand->email,
                'error' => $e->getMessage()
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of new application', [
                'application_id' => $application->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyBrandOfNewApplication(CampaignApplication $application): void
    {
        try {
            $application->load(['campaign', 'campaign.brand', 'creator']);
            
            
            Mail::to($application->campaign->brand->email)->send(new \App\Mail\ApplicationReceived($application));
            
            Log::info('Application received email sent successfully to brand', [
                'application_id' => $application->id,
                'campaign_id' => $application->campaign_id,
                'creator_id' => $application->creator_id,
                'creator_name' => $application->creator->name,
                'brand_email' => $application->campaign->brand->email
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send application received email to brand', [
                'application_id' => $application->id,
                'campaign_id' => $application->campaign_id,
                'brand_email' => $application->campaign->brand->email ?? 'unknown',
                'error' => $e->getMessage()
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of new bid', [
                'bid_id' => $bid->id,
                'error' => $e->getMessage()
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of payment activity', [
                'user_id' => $user->id,
                'payment_type' => $paymentType,
                'error' => $e->getMessage()
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of portfolio update', [
                'user_id' => $user->id,
                'update_type' => $updateType,
                'error' => $e->getMessage()
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of system activity', [
                'activity_type' => $activityType,
                'error' => $e->getMessage()
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
                
                
                self::sendSocketNotification($admin->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admin of new student verification', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfStudentVerificationApproval(User $user, array $approvalData = []): void
    {
        try {
            $notification = Notification::createSystemActivity($user->id, array_merge($approvalData, [
                'activity_type' => 'student_verification_approved',
                'approved_at' => now()->toISOString(),
                'duration_months' => $approvalData['duration_months'] ?? 12,
                'expires_at' => $approvalData['expires_at'] ?? null,
            ]));
            
            
            self::sendSocketNotification($user->id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of student verification approval', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfStudentVerificationRejection(User $user, array $rejectionData = []): void
    {
        try {
            $notification = Notification::createSystemActivity($user->id, array_merge($rejectionData, [
                'activity_type' => 'student_verification_rejected',
                'rejected_at' => $rejectionData['rejected_at'] ?? now()->toISOString(),
                'rejection_reason' => $rejectionData['rejection_reason'] ?? null,
            ]));
            
            
            self::sendSocketNotification($user->id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of student verification rejection', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorsOfNewProject(Campaign $campaign): void
    {
        try {
            
            $creators = User::where('role', 'creator')->get();
            
            foreach ($creators as $creator) {
                $notification = Notification::createNewProject(
                    $creator->id,
                    $campaign->id,
                    $campaign->title
                );
                
                
                self::sendSocketNotification($creator->id, $notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify creators of new project', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyBrandOfProjectStatus(Campaign $campaign, string $status, string $reason = null): void
    {
        try {
            if ($status === 'approved') {
                $notification = Notification::createProjectApproved(
                    $campaign->brand_id,
                    $campaign->id,
                    $campaign->title
                );
            } else {
                $notification = Notification::createProjectRejected(
                    $campaign->brand_id,
                    $campaign->id,
                    $campaign->title,
                    $reason
                );
            }
            
            
            self::sendSocketNotification($campaign->brand_id, $notification);

            
            try {
                $campaign->load(['brand']);
                if ($status === 'approved') {
                    Mail::to($campaign->brand->email)->send(new \App\Mail\CampaignApproved($campaign));
                } else {
                    Mail::to($campaign->brand->email)->send(new \App\Mail\CampaignRejected($campaign));
                }
            } catch (\Exception $emailError) {
                Log::error('Failed to send campaign status email', [
                    'campaign_id' => $campaign->id,
                    'brand_email' => $campaign->brand->email,
                    'status' => $status,
                    'error' => $emailError->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify brand of project status', [
                'campaign_id' => $campaign->id,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfProposalStatus(Bid $bid, string $status, string $reason = null): void
    {
        try {
            $campaign = $bid->campaign;
            $brand = $campaign->brand;
            
            if ($status === 'accepted') {
                $notification = Notification::createProposalApproved(
                    $bid->user_id,
                    $campaign->id,
                    $campaign->title,
                    $brand->name
                );
            } else {
                $notification = Notification::createProposalRejected(
                    $bid->user_id,
                    $campaign->id,
                    $campaign->title,
                    $brand->name,
                    $reason
                );
            }
            
            
            self::sendSocketNotification($bid->user_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of proposal status', [
                'bid_id' => $bid->id,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfNewMessage(Message $message): void
    {
        try {
            $chatRoom = $message->chatRoom;
            $sender = $message->sender;
            
            
            $recipientId = $chatRoom->brand_id === $sender->id ? $chatRoom->creator_id : $chatRoom->brand_id;
            
            
            $messagePreview = strlen($message->message) > 50 
                ? substr($message->message, 0, 50) . '...' 
                : $message->message;
            
            $notification = Notification::createNewMessage(
                $recipientId,
                $sender->id,
                $sender->name,
                $messagePreview,
                'campaign'
            );
            
            
            self::sendSocketNotification($recipientId, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of new message', [
                'message_id' => $message->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfNewDirectMessage(DirectMessage $message): void
    {
        try {
            $chatRoom = $message->directChatRoom;
            $sender = $message->sender;
            
            
            $recipientId = $chatRoom->brand_id === $sender->id ? $chatRoom->creator_id : $chatRoom->brand_id;
            
            
            $messagePreview = strlen($message->message) > 50 
                ? substr($message->message, 0, 50) . '...' 
                : $message->message;
            
            $notification = Notification::createNewMessage(
                $recipientId,
                $sender->id,
                $sender->name,
                $messagePreview,
                'direct'
            );
            
            
            self::sendSocketNotification($recipientId, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of new direct message', [
                'message_id' => $message->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function sendSocketNotification(int $userId, Notification $notification): void
    {
        try {
            
            $socketServer = null;
            
            
            if (app()->bound('socket.server')) {
                $socketServer = app('socket.server');
            }
            
            
            if (!$socketServer && isset($GLOBALS['socket_server'])) {
                $socketServer = $GLOBALS['socket_server'];
            }
            
            if ($socketServer) {
                $notificationData = [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'data' => $notification->data,
                    'is_read' => $notification->is_read,
                    'created_at' => $notification->created_at->toISOString(),
                ];
                
                $socketServer->to("user_{$userId}")->emit('new_notification', $notificationData);
                
                Log::info('Socket notification sent successfully', [
                    'user_id' => $userId,
                    'notification_id' => $notification->id,
                    'room' => "user_{$userId}"
                ]);
            } else {
                Log::warning('Socket server not available for notification', [
                    'user_id' => $userId,
                    'notification_id' => $notification->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send socket notification', [
                'user_id' => $userId,
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    
    public static function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }

    
    public static function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();
            
        if ($notification) {
            return $notification->markAsRead();
        }
        
        return false;
    }

    
    public static function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    
    public static function notifyUserOfNewOffer($offer): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $offer->creator_id,
                'type' => 'new_offer',
                'title' => 'Nova Oferta Recebida',
                'message' => "Você recebeu uma nova oferta de R$ {$offer->formatted_budget}",
                'data' => [
                    'offer_id' => $offer->id,
                    'brand_id' => $offer->brand_id,
                    'brand_name' => $offer->brand->name,
                    'budget' => $offer->budget,
                    'estimated_days' => $offer->estimated_days,
                ],
                'is_read' => false,
            ]);
            
            
            self::sendSocketNotification($offer->creator_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of new offer', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfOfferAccepted($offer): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $offer->brand_id,
                'type' => 'offer_accepted',
                'title' => 'Oferta Aceita',
                'message' => "Sua oferta foi aceita pelo criador",
                'data' => [
                    'offer_id' => $offer->id,
                    'creator_id' => $offer->creator_id,
                    'creator_name' => $offer->creator->name,
                    'budget' => $offer->budget,
                    'contract_id' => $offer->contract->id ?? null,
                ],
                'is_read' => false,
            ]);
            
            
            self::sendSocketNotification($offer->brand_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of offer acceptance', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfOfferRejected($offer, string $reason = null): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $offer->brand_id,
                'type' => 'offer_rejected',
                'title' => 'Oferta Rejeitada',
                'message' => "Sua oferta foi rejeitada pelo criador" . ($reason ? ": {$reason}" : ""),
                'data' => [
                    'offer_id' => $offer->id,
                    'creator_id' => $offer->creator_id,
                    'creator_name' => $offer->creator->name,
                    'rejection_reason' => $reason,
                ],
                'is_read' => false,
            ]);
            
            
            self::sendSocketNotification($offer->brand_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of offer rejection', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfOfferCancelled($offer): void
    {
        try {
            
            $notification = Notification::createOfferCancelled($offer->creator_id, [
                'offer_id' => $offer->id,
                'offer_title' => $offer->title,
                'brand_id' => $offer->brand_id,
                'brand_name' => $offer->brand->name,
                'cancelled_at' => now()->toISOString(),
            ]);
            
            
            self::sendSocketNotification($offer->creator_id, $notification);
            
        } catch (\Exception $e) {
            Log::error('Failed to notify user of offer cancellation', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyBrandOfReviewRequired($contract): void
    {
        try {
            $notification = Notification::createReviewRequired($contract->brand_id, [
                'contract_id' => $contract->id,
                'contract_title' => $contract->title,
                'creator_id' => $contract->creator_id,
                'creator_name' => $contract->creator->name,
                'completed_at' => $contract->completed_at->toISOString(),
            ]);
            
            
            self::sendSocketNotification($contract->brand_id, $notification);
            
        } catch (\Exception $e) {
            Log::error('Failed to notify brand of review requirement', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfContractCompleted($contract): void
    {
        try {
            $notification = Notification::createContractCompleted($contract->creator_id, [
                'contract_id' => $contract->id,
                'contract_title' => $contract->title,
                'brand_id' => $contract->brand_id,
                'brand_name' => $contract->brand->name,
                'completed_at' => $contract->completed_at->toISOString(),
            ]);
            
            
            self::sendSocketNotification($contract->creator_id, $notification);
            
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of contract completion', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfNewReview($review): void
    {
        try {
            $notification = Notification::createNewReview($review->reviewed_id, [
                'review_id' => $review->id,
                'contract_id' => $review->contract_id,
                'contract_title' => $review->contract->title,
                'reviewer_id' => $review->reviewer_id,
                'reviewer_name' => $review->reviewer->name,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at->toISOString(),
            ]);
            
            
            self::sendSocketNotification($review->reviewed_id, $notification);
            
        } catch (\Exception $e) {
            Log::error('Failed to notify user of new review', [
                'review_id' => $review->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfContractTerminated($contract, string $reason = null): void
    {
        try {
            
            $users = [$contract->brand_id, $contract->creator_id];
            
            foreach ($users as $userId) {
                $notification = Notification::createContractTerminated($userId, [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'terminated_at' => $contract->cancelled_at->toISOString(),
                    'reason' => $reason,
                ]);
                
                
                self::sendSocketNotification($userId, $notification);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to notify users of contract termination', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfPaymentAvailable($contract): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $contract->creator_id,
                'type' => 'payment_available',
                'title' => 'Pagamento Disponível',
                'message' => "O pagamento do contrato '{$contract->title}' está disponível para saque. Valor: R$ " . number_format($contract->creator_amount, 2, ',', '.'),
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'amount' => $contract->creator_amount,
                    'formatted_amount' => 'R$ ' . number_format($contract->creator_amount, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($contract->creator_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of payment available', [
                'contract_id' => $contract->id,
                'creator_id' => $contract->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyBrandOfPaymentSuccessful($contract): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $contract->brand_id,
                'type' => 'payment_successful',
                'title' => 'Pagamento Processado',
                'message' => "O pagamento do contrato '{$contract->title}' foi processado com sucesso. Valor: R$ " . number_format($contract->budget, 2, ',', '.'),
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'amount' => $contract->budget,
                    'formatted_amount' => 'R$ ' . number_format($contract->budget, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($contract->brand_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify brand of payment successful', [
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfPaymentReceived($contract): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $contract->creator_id,
                'type' => 'payment_received',
                'title' => 'Pagamento Recebido',
                'message' => "O pagamento do contrato '{$contract->title}' foi recebido. Valor: R$ " . number_format($contract->creator_amount, 2, ',', '.'),
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'amount' => $contract->creator_amount,
                    'formatted_amount' => 'R$ ' . number_format($contract->creator_amount, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($contract->creator_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of payment received', [
                'contract_id' => $contract->id,
                'creator_id' => $contract->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyBrandOfPaymentFailed($contract): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $contract->brand_id,
                'type' => 'payment_failed',
                'title' => 'Falha no Pagamento',
                'message' => "O pagamento do contrato '{$contract->title}' falhou. Verifique seus dados de pagamento.",
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'amount' => $contract->budget,
                    'formatted_amount' => 'R$ ' . number_format($contract->budget, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($contract->brand_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify brand of payment failed', [
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfPaymentPending($contract): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $contract->creator_id,
                'type' => 'payment_pending',
                'title' => 'Pagamento Pendente',
                'message' => "O pagamento do contrato '{$contract->title}' está sendo processado. Você será notificado quando for confirmado.",
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'amount' => $contract->creator_amount,
                    'formatted_amount' => 'R$ ' . number_format($contract->creator_amount, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($contract->creator_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of payment pending', [
                'contract_id' => $contract->id,
                'creator_id' => $contract->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyBrandOfNewDeliveryMaterial($material): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $material->brand_id,
                'type' => 'new_delivery_material',
                'title' => 'Novo Material de Entrega',
                'message' => "O criador {$material->creator->name} enviou um novo material para o contrato '{$material->contract->title}'.",
                'data' => [
                    'material_id' => $material->id,
                    'contract_id' => $material->contract_id,
                    'contract_title' => $material->contract->title,
                    'creator_name' => $material->creator->name,
                    'file_name' => $material->file_name,
                    'media_type' => $material->media_type,
                    'submitted_at' => $material->submitted_at->toISOString(),
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($material->brand_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify brand of new delivery material', [
                'material_id' => $material->id,
                'brand_id' => $material->brand_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfDeliveryMaterialApproval($material): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $material->creator_id,
                'type' => 'delivery_material_approved',
                'title' => 'Material Aprovado',
                'message' => "Seu material '{$material->file_name}' foi aprovado para o contrato '{$material->contract->title}'.",
                'data' => [
                    'material_id' => $material->id,
                    'contract_id' => $material->contract_id,
                    'contract_title' => $material->contract->title,
                    'brand_name' => $material->brand->name,
                    'file_name' => $material->file_name,
                    'approved_at' => $material->reviewed_at->toISOString(),
                    'comment' => $material->comment,
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($material->creator_id, $notification);

            
            try {
                $material->load(['contract', 'creator', 'brand']);
                Mail::to($material->creator->email)->send(new \App\Mail\DeliveryMaterialApproved($material));
            } catch (\Exception $emailError) {
                Log::error('Failed to send delivery material approval email', [
                    'material_id' => $material->id,
                    'creator_email' => $material->creator->email,
                    'error' => $emailError->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of delivery material approval', [
                'material_id' => $material->id,
                'creator_id' => $material->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfDeliveryMaterialRejection($material): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $material->creator_id,
                'type' => 'delivery_material_rejected',
                'title' => 'Material Rejeitado',
                'message' => "Seu material '{$material->file_name}' foi rejeitado para o contrato '{$material->contract_id}'.",
                'data' => [
                    'material_id' => $material->id,
                    'contract_id' => $material->contract_id,
                    'contract_title' => $material->contract->title,
                    'brand_name' => $material->brand->name,
                    'file_name' => $material->file_name,
                    'rejected_at' => $material->reviewed_at->toISOString(),
                    'rejection_reason' => $material->rejection_reason,
                    'comment' => $material->comment,
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($material->creator_id, $notification);

            
            try {
                $material->load(['contract', 'creator', 'brand']);
                Mail::to($material->creator->email)->send(new \App\Mail\DeliveryMaterialRejected($material));
            } catch (\Exception $emailError) {
                Log::error('Failed to send delivery material rejection email', [
                    'material_id' => $material->id,
                    'creator_email' => $material->creator->email,
                    'error' => $emailError->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of delivery material rejection', [
                'material_id' => $material->id,
                'creator_id' => $material->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyBrandOfDeliveryMaterialAction($material, $action): void
    {
        try {
            $actionText = $action === 'approved' ? 'aprovou' : 'rejeitou';
            $actionTitle = $action === 'approved' ? 'Material Aprovado' : 'Material Rejeitado';
            
            $notification = Notification::create([
                'user_id' => $material->brand_id,
                'type' => 'delivery_material_action',
                'title' => $actionTitle,
                'message' => "Você {$actionText} o material '{$material->file_name}' do criador {$material->creator->name} para o contrato '{$material->contract->title}'.",
                'data' => [
                    'material_id' => $material->id,
                    'contract_id' => $material->contract_id,
                    'contract_title' => $material->contract->title,
                    'creator_name' => $material->creator->name,
                    'file_name' => $material->file_name,
                    'media_type' => $material->media_type,
                    'action' => $action,
                    'action_at' => $material->reviewed_at->toISOString(),
                    'comment' => $material->comment,
                    'rejection_reason' => $material->rejection_reason,
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($material->brand_id, $notification);

            
            try {
                $material->load(['contract', 'creator', 'brand']);
                if ($action === 'approved') {
                    Mail::to($material->brand->email)->send(new \App\Mail\DeliveryMaterialApproved($material));
                } else {
                    Mail::to($material->brand->email)->send(new \App\Mail\DeliveryMaterialRejected($material));
                }
            } catch (\Exception $emailError) {
                Log::error('Failed to send delivery material action email to brand', [
                    'material_id' => $material->id,
                    'brand_email' => $material->brand->email,
                    'action' => $action,
                    'error' => $emailError->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify brand of delivery material action', [
                'material_id' => $material->id,
                'brand_id' => $material->brand_id,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfMilestoneApproval($milestone): void
    {
        try {
            $milestone->load(['contract.creator', 'contract.brand']);
            
            $notification = Notification::create([
                'user_id' => $milestone->contract->creator_id,
                'type' => 'milestone_approved',
                'title' => 'Milestone Aprovado',
                'message' => "O milestone '{$milestone->title}' foi aprovado para o contrato '{$milestone->contract->title}'.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'brand_name' => $milestone->contract->brand->name,
                    'milestone_type' => $milestone->milestone_type,
                    'approved_at' => now()->toISOString(),
                    'comment' => $milestone->comment,
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($milestone->contract->creator_id, $notification);

            
            try {
                Mail::to($milestone->contract->creator->email)->send(new \App\Mail\MilestoneApproved($milestone));
            } catch (\Exception $emailError) {
                Log::error('Failed to send milestone approval email', [
                    'milestone_id' => $milestone->id,
                    'creator_email' => $milestone->contract->creator->email,
                    'error' => $emailError->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of milestone approval', [
                'milestone_id' => $milestone->id,
                'creator_id' => $milestone->contract->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfMilestoneRejection($milestone): void
    {
        try {
            $milestone->load(['contract.creator', 'contract.brand']);
            
            $notification = Notification::create([
                'user_id' => $milestone->contract->creator_id,
                'type' => 'milestone_rejected',
                'title' => 'Milestone Rejeitado',
                'message' => "O milestone '{$milestone->title}' foi rejeitado para o contrato '{$milestone->contract->title}'.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'brand_name' => $milestone->contract->brand->name,
                    'milestone_type' => $milestone->milestone_type,
                    'rejected_at' => now()->toISOString(),
                    'comment' => $milestone->comment,
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($milestone->contract->creator_id, $notification);

            
            try {
                Mail::to($milestone->contract->creator->email)->send(new \App\Mail\MilestoneRejected($milestone));
            } catch (\Exception $emailError) {
                Log::error('Failed to send milestone rejection email', [
                    'milestone_id' => $milestone->id,
                    'creator_email' => $milestone->contract->creator->email,
                    'error' => $emailError->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of milestone rejection', [
                'milestone_id' => $milestone->id,
                'creator_id' => $milestone->contract->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfMilestoneDelay($milestone): void
    {
        try {
            $milestone->load(['contract.creator', 'contract.brand']);
            
            $notification = Notification::create([
                'user_id' => $milestone->contract->creator_id,
                'type' => 'milestone_delay_warning',
                'title' => 'Aviso de Atraso - Milestone',
                'message' => "⚠️ O milestone '{$milestone->title}' está atrasado. Justifique o atraso para evitar penalidades de 7 dias sem novos convites.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'brand_name' => $milestone->contract->brand->name,
                    'milestone_type' => $milestone->milestone_type,
                    'deadline' => $milestone->deadline->toISOString(),
                    'days_overdue' => $milestone->getDaysOverdue(),
                    'warning_sent_at' => now()->toISOString(),
                ],
                'read_at' => null,
            ]);

            self::sendSocketNotification($milestone->contract->creator_id, $notification);

            
            try {
                Mail::to($milestone->contract->creator->email)->send(new \App\Mail\MilestoneDelayWarning($milestone));
            } catch (\Exception $emailError) {
                Log::error('Failed to send milestone delay warning email', [
                    'milestone_id' => $milestone->id,
                    'creator_email' => $milestone->contract->creator->email,
                    'error' => $emailError->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of milestone delay warning', [
                'milestone_id' => $milestone->id,
                'creator_id' => $milestone->contract->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfPaymentCompleted($jobPayment): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $jobPayment->creator_id,
                'type' => 'payment_completed',
                'title' => 'Pagamento Concluído',
                'message' => "Seu pagamento de R$ " . number_format($jobPayment->creator_amount, 2, ',', '.') . " foi processado com sucesso.",
                'data' => [
                    'job_payment_id' => $jobPayment->id,
                    'contract_id' => $jobPayment->contract_id,
                    'amount' => $jobPayment->creator_amount,
                    'formatted_amount' => 'R$ ' . number_format($jobPayment->creator_amount, 2, ',', '.'),
                    'transaction_id' => $jobPayment->transaction_id,
                    'processed_at' => $jobPayment->processed_at ? $jobPayment->processed_at->toISOString() : null,
                ],
                'is_read' => false,
            ]);
            
            
            self::sendSocketNotification($jobPayment->creator_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of payment completed', [
                'job_payment_id' => $jobPayment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyUserOfPaymentFailed($jobPayment, string $reason = null): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $jobPayment->creator_id,
                'type' => 'payment_failed',
                'title' => 'Falha no Pagamento',
                'message' => "Falha no processamento do pagamento. " . ($reason ? "Motivo: {$reason}" : "Tente novamente mais tarde."),
                'data' => [
                    'job_payment_id' => $jobPayment->id,
                    'contract_id' => $jobPayment->contract_id,
                    'amount' => $jobPayment->creator_amount,
                    'formatted_amount' => 'R$ ' . number_format($jobPayment->creator_amount, 2, ',', '.'),
                    'failure_reason' => $reason,
                ],
                'is_read' => false,
            ]);
            
            
            self::sendSocketNotification($jobPayment->creator_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to notify user of payment failed', [
                'job_payment_id' => $jobPayment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    
    public static function notifyCreatorOfProposalApproval(CampaignApplication $application): void
    {
        try {
            $application->load(['campaign.brand', 'creator']);
            
            $notification = Notification::create([
                'user_id' => $application->creator_id,
                'type' => 'proposal_approved',
                'title' => '💖 Parabéns! Seu perfil foi selecionado!',
                'message' => "Parabéns! Você tem a cara da marca e foi selecionada para uma parceria de sucesso! Prepare-se para mostrar todo o seu talento e representar a NEXA com criatividade e profissionalismo.",
                'data' => [
                    'application_id' => $application->id,
                    'campaign_id' => $application->campaign_id,
                    'campaign_title' => $application->campaign->title,
                    'brand_id' => $application->campaign->brand_id,
                    'brand_name' => $application->campaign->brand->name,
                    'proposed_budget' => $application->proposed_budget,
                    'estimated_delivery_days' => $application->estimated_delivery_days,
                    'approved_at' => $application->approved_at->toISOString(),
                ],
                'read_at' => null,
            ]);

            
            self::sendSocketNotification($application->creator_id, $notification);

            
            try {
                Mail::to($application->creator->email)->send(new \App\Mail\ProposalApproved($application));
            } catch (\Exception $emailError) {
                Log::error('Failed to send proposal approval email', [
                    'application_id' => $application->id,
                    'creator_email' => $application->creator->email,
                    'error' => $emailError->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify creator of proposal approval', [
                'application_id' => $application->id,
                'creator_id' => $application->creator_id,
                'error' => $e->getMessage()
            ]);
        }
    }
} 
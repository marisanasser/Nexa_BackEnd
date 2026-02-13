<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Models\Common\Notification;
use App\Models\Payment\WithdrawalMethod;
use Exception;
use Illuminate\Support\Facades\Log;

class PaymentNotificationService
{
    public static function notifyCreatorOfPaymentAvailable($contract): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $contract->creator_id,
                'type' => 'payment_available',
                'title' => 'Pagamento Disponível',
                'message' => "O pagamento do contrato '{$contract->title}' está disponível para saque. Valor: R$ ".number_format($contract->creator_amount, 2, ',', '.'),
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'amount' => $contract->creator_amount,
                    'formatted_amount' => 'R$ '.number_format($contract->creator_amount, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($contract->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify creator of payment available', [
                'contract_id' => $contract->id,
                'creator_id' => $contract->creator_id,
                'error' => $e->getMessage(),
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
                'message' => "O pagamento do contrato '{$contract->title}' foi processado com sucesso. Valor: R$ ".number_format($contract->budget, 2, ',', '.'),
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'amount' => $contract->budget,
                    'formatted_amount' => 'R$ '.number_format($contract->budget, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($contract->brand_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify brand of payment successful', [
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'error' => $e->getMessage(),
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
                'message' => "O pagamento do contrato '{$contract->title}' foi recebido. Valor: R$ ".number_format($contract->creator_amount, 2, ',', '.'),
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'amount' => $contract->creator_amount,
                    'formatted_amount' => 'R$ '.number_format($contract->creator_amount, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($contract->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify creator of payment received', [
                'contract_id' => $contract->id,
                'creator_id' => $contract->creator_id,
                'error' => $e->getMessage(),
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
                    'formatted_amount' => 'R$ '.number_format($contract->budget, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($contract->brand_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify brand of payment failed', [
                'contract_id' => $contract->id,
                'brand_id' => $contract->brand_id,
                'error' => $e->getMessage(),
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
                    'formatted_amount' => 'R$ '.number_format($contract->creator_amount, 2, ',', '.'),
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($contract->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify creator of payment pending', [
                'contract_id' => $contract->id,
                'creator_id' => $contract->creator_id,
                'error' => $e->getMessage(),
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
                'message' => 'Seu pagamento de R$ '.number_format($jobPayment->creator_amount, 2, ',', '.').' foi processado com sucesso.',
                'data' => [
                    'job_payment_id' => $jobPayment->id,
                    'contract_id' => $jobPayment->contract_id,
                    'amount' => $jobPayment->creator_amount,
                    'formatted_amount' => 'R$ '.number_format($jobPayment->creator_amount, 2, ',', '.'),
                    'transaction_id' => $jobPayment->transaction_id,
                    'processed_at' => $jobPayment->processed_at ? $jobPayment->processed_at->toISOString() : null,
                ],
                'is_read' => false,
            ]);

            NotificationService::sendSocketNotification($jobPayment->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of payment completed', [
                'job_payment_id' => $jobPayment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfPaymentFailed($jobPayment, ?string $reason = null): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $jobPayment->creator_id,
                'type' => 'payment_failed',
                'title' => 'Falha no Pagamento',
                'message' => 'Falha no processamento do pagamento. '.($reason ? "Motivo: {$reason}" : 'Tente novamente mais tarde.'),
                'data' => [
                    'job_payment_id' => $jobPayment->id,
                    'contract_id' => $jobPayment->contract_id,
                    'amount' => $jobPayment->creator_amount,
                    'formatted_amount' => 'R$ '.number_format($jobPayment->creator_amount, 2, ',', '.'),
                    'failure_reason' => $reason,
                ],
                'is_read' => false,
            ]);

            NotificationService::sendSocketNotification($jobPayment->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of payment failed', [
                'job_payment_id' => $jobPayment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfPaymentRefunded($jobPayment, ?string $reason = null): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $jobPayment->creator_id,
                'type' => 'payment_refunded',
                'title' => 'Pagamento Estornado',
                'message' => 'O pagamento de R$ '.number_format($jobPayment->creator_amount, 2, ',', '.').' foi estornado.'.($reason ? " Motivo: {$reason}" : ''),
                'data' => [
                    'job_payment_id' => $jobPayment->id,
                    'contract_id' => $jobPayment->contract_id,
                    'amount' => $jobPayment->creator_amount,
                    'formatted_amount' => 'R$ '.number_format($jobPayment->creator_amount, 2, ',', '.'),
                    'refund_reason' => $reason,
                    'refunded_at' => now()->toISOString(),
                ],
                'is_read' => false,
            ]);

            NotificationService::sendSocketNotification($jobPayment->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of payment refunded', [
                'job_payment_id' => $jobPayment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfPlatformFundingSuccess(int $userId, float $amount, array $fundingData): void
    {
        try {
            $notification = Notification::createPlatformFundingSuccess(
                $userId,
                $amount,
                $fundingData
            );

            NotificationService::sendSocketNotification($userId, $notification);

            Log::info('Platform funding success notification created', [
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'amount' => $amount,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to create platform funding success notification', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfWithdrawalStatus($withdrawal, string $status, ?string $reason = null): void
    {
        try {
            if ('completed' === $status) {
                $withdrawalMethod = WithdrawalMethod::findByCode($withdrawal->withdrawal_method);
                $methodName = $withdrawalMethod ? $withdrawalMethod->name : $withdrawal->withdrawal_method_label;

                $withdrawalData = [
                    'withdrawal_id' => $withdrawal->id,
                    'method' => $withdrawal->withdrawal_method,
                    'method_name' => $methodName,
                    'transaction_id' => $withdrawal->transaction_id,
                    'processed_at' => $withdrawal->processed_at ? $withdrawal->processed_at->toDateTimeString() : null,
                ];

                if ($withdrawal->withdrawal_details) {
                    $withdrawalData = array_merge($withdrawalData, $withdrawal->withdrawal_details);
                }

                $notification = Notification::createWithdrawalSuccess(
                    $withdrawal->creator_id,
                    $withdrawal->amount,
                    $withdrawal->net_amount,
                    $withdrawal->total_fees,
                    $withdrawalData
                );
            } else {
                $notification = Notification::create([
                    'user_id' => $withdrawal->creator_id,
                    'type' => 'withdrawal_'.$status,
                    'title' => 'failed' === $status ? 'Falha no Saque' : 'Saque Cancelado',
                    'message' => 'failed' === $status
                        ? "Falha no processamento do saque de {$withdrawal->formatted_amount}. Motivo: {$reason}"
                        : "Seu saque de {$withdrawal->formatted_amount} foi cancelado.".($reason ? " Motivo: {$reason}" : ''),
                    'data' => [
                        'withdrawal_id' => $withdrawal->id,
                        'amount' => $withdrawal->amount,
                        'method' => $withdrawal->withdrawal_method,
                        'status' => $status,
                        'reason' => $reason,
                    ],
                    'is_read' => false,
                ]);
            }

            NotificationService::sendSocketNotification($withdrawal->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to create withdrawal notification', [
                'withdrawal_id' => $withdrawal->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

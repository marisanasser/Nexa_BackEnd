<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Models\CreatorBalance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;
use Stripe\Stripe;
use Stripe\Account;

class WithdrawalController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Check if user's Stripe account has payouts enabled
     */
    private function checkStripePayoutsEnabled(User $user): array
    {
        try {
            if (!$user->stripe_account_id) {
                return [
                    'enabled' => false,
                    'message' => 'Você precisa configurar sua conta Stripe antes de solicitar saques. Acesse as configurações do Stripe para completar o cadastro.',
                    'action_required' => 'stripe_setup'
                ];
            }

            $stripeAccount = Account::retrieve($user->stripe_account_id);
            
            if (!$stripeAccount->payouts_enabled) {
                return [
                    'enabled' => false,
                    'message' => 'Sua conta Stripe ainda não está habilitada para receber pagamentos. Complete o processo de verificação no Stripe para ativar os saques.',
                    'action_required' => 'stripe_verification'
                ];
            }

            return [
                'enabled' => true,
                'message' => 'Conta Stripe configurada corretamente'
            ];

        } catch (\Exception $e) {
            Log::error('Error checking Stripe payouts status', [
                'user_id' => $user->id,
                'stripe_account_id' => $user->stripe_account_id,
                'error' => $e->getMessage()
            ]);

            return [
                'enabled' => false,
                'message' => 'Erro ao verificar status da conta Stripe. Tente novamente mais tarde.',
                'action_required' => 'retry'
            ];
        }
    }

    /**
     * Create a withdrawal request
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can request withdrawals',
            ], 403);
        }

        // Check Stripe payouts status before allowing withdrawal
        $payoutsStatus = $this->checkStripePayoutsEnabled($user);
        if (!$payoutsStatus['enabled']) {
            return response()->json([
                'success' => false,
                'message' => $payoutsStatus['message'],
                'action_required' => $payoutsStatus['action_required'],
                'blocked' => true
            ], 403);
        }

        // Get the withdrawal method from database
        $withdrawalMethod = \App\Models\WithdrawalMethod::findByCode($request->withdrawal_method);
        
        if (!$withdrawalMethod) {
            Log::error('Invalid withdrawal method requested', [
                'user_id' => $user->id,
                'requested_method' => $request->withdrawal_method,
                'available_methods' => \App\Models\WithdrawalMethod::getActiveMethods()->pluck('code')->toArray()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid withdrawal method: ' . $request->withdrawal_method,
            ], 400);
        }

        // Check if user has registered bank account for Pagar.me withdrawals
        if ($withdrawalMethod->code === 'pagarme_bank_transfer') {
            $bankAccount = \App\Models\BankAccount::where('user_id', $user->id)->first();
            
            if (!$bankAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você precisa registrar uma conta bancária antes de solicitar um saque. Acesse seu perfil para registrar sua conta bancária.',
                ], 400);
            }
        }

        // Build dynamic validation rules
        $validationRules = [
            'amount' => 'required|numeric|min:0.01',
            'withdrawal_method' => 'required|string',
        ];

        // For Pagar.me, withdrawal_details is optional
        if ($withdrawalMethod->code === 'pagarme_bank_transfer') {
            $validationRules['withdrawal_details'] = 'nullable|array';
        } else {
            $validationRules['withdrawal_details'] = 'nullable|array';
        }

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            Log::error('Withdrawal validation failed', [
                'user_id' => $user->id,
                'errors' => $validator->errors(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validate withdrawal details using the method's validation
        // Temporarily comment out to debug
        // $detailErrors = $withdrawalMethod->validateWithdrawalDetails($request->withdrawal_details);
        // if (!empty($detailErrors)) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Invalid withdrawal details',
        //         'errors' => $detailErrors,
        //     ], 422);
        // }

        try {
            $balance = CreatorBalance::where('creator_id', $user->id)->first();

            if (!$balance) {
                Log::warning('No balance found for creator, creating new balance', [
                    'creator_id' => $user->id
                ]);
                
                $balance = CreatorBalance::create([
                    'creator_id' => $user->id,
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_earned' => 0,
                    'total_withdrawn' => 0,
                ]);
            }

            // Check if user has sufficient balance
            if (!$balance->canWithdraw($request->amount)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo insuficiente para o saque. Saldo disponível: ' . $balance->formatted_available_balance,
                ], 400);
            }

            // Check if amount is within method limits
            if (!$withdrawalMethod->isAmountValid($request->amount)) {
                return response()->json([
                    'success' => false,
                    'message' => "Valor deve estar entre {$withdrawalMethod->formatted_min_amount} e {$withdrawalMethod->formatted_max_amount} para {$withdrawalMethod->name}",
                ], 400);
            }

            // Check if user has pending withdrawals
            $pendingWithdrawals = $user->withdrawals()
                ->whereIn('status', ['pending', 'processing'])
                ->count();

            if ($pendingWithdrawals >= 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você tem muitos saques pendentes. Aguarde o processamento dos saques atuais.',
                ], 400);
            }

            $withdrawal = Withdrawal::create([
                'creator_id' => $user->id,
                'amount' => $request->amount,
                'platform_fee' => 5.00, // 5% platform fee
                'fixed_fee' => 5.00, // R$5 fixed platform fee
                'withdrawal_method' => $request->withdrawal_method,
                'withdrawal_details' => $request->withdrawal_details ?? [],
                'status' => 'pending',
            ]);

            // If it's a Pagar.me withdrawal, store bank account info
            if ($withdrawalMethod->code === 'pagarme_bank_transfer') {
                $bankAccount = \App\Models\BankAccount::where('user_id', $user->id)->first();
                if ($bankAccount) {
                    $withdrawal->update([
                        'withdrawal_details' => array_merge($request->withdrawal_details ?? [], [
                            'bank_code' => $bankAccount->bank_code,
                            'agencia' => $bankAccount->agencia,
                            'agencia_dv' => $bankAccount->agencia_dv,
                            'conta' => $bankAccount->conta,
                            'conta_dv' => $bankAccount->conta_dv,
                            'cpf' => $bankAccount->cpf,
                            'name' => $bankAccount->name,
                        ])
                    ]);
                }
            }

            // Deduct from available balance
            $balance->withdraw($request->amount);

            Log::info('Withdrawal request created successfully', [
                'withdrawal_id' => $withdrawal->id,
                'creator_id' => $user->id,
                'amount' => $request->amount,
                'method' => $request->withdrawal_method,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully',
                'data' => [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->formatted_amount,
                    'method' => $withdrawal->withdrawal_method_label,
                    'status' => $withdrawal->status,
                    'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating withdrawal request', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit withdrawal request. Please try again.',
            ], 500);
        }
    }

    /**
     * Get withdrawal history for the authenticated creator
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can access withdrawal history',
            ], 403);
        }

        try {
            $status = $request->get('status');
            $query = $user->withdrawals();

            if ($status) {
                $query->where('status', $status);
            }

            $withdrawals = $query->orderBy('created_at', 'desc')
                ->paginate(10);

            $withdrawals->getCollection()->transform(function ($withdrawal) {
                return [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->formatted_amount,
                    'platform_fee' => $withdrawal->formatted_platform_fee_amount,
                    'fixed_fee' => $withdrawal->formatted_fixed_fee,
                    'percentage_fee' => $withdrawal->formatted_percentage_fee_amount,
                    'total_fees' => $withdrawal->formatted_total_fees,
                    'net_amount' => $withdrawal->formatted_net_amount,
                    'method' => $withdrawal->withdrawal_method_label,
                    'status' => $withdrawal->status,
                    'status_color' => $withdrawal->status_color,
                    'status_badge_color' => $withdrawal->status_badge_color,
                    'transaction_id' => $withdrawal->transaction_id,
                    'failure_reason' => $withdrawal->failure_reason,
                    'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'processed_at' => $withdrawal->processed_at?->format('Y-m-d H:i:s'),
                    'days_since_created' => $withdrawal->days_since_created,
                    'is_recent' => $withdrawal->is_recent,
                    'bank_account_info' => $withdrawal->bank_account_info,
                    'pix_info' => $withdrawal->pix_info,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $withdrawals,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal history', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawal history',
            ], 500);
        }
    }

    /**
     * Get a specific withdrawal
     */
    public function show(int $id): JsonResponse
    {
        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can access withdrawal details',
            ], 403);
        }

        try {
            $withdrawal = Withdrawal::where('creator_id', $user->id)
                ->find($id);

            if (!$withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->formatted_amount,
                    'platform_fee' => $withdrawal->formatted_platform_fee_amount,
                    'fixed_fee' => $withdrawal->formatted_fixed_fee,
                    'percentage_fee' => $withdrawal->formatted_percentage_fee_amount,
                    'total_fees' => $withdrawal->formatted_total_fees,
                    'net_amount' => $withdrawal->formatted_net_amount,
                    'method' => $withdrawal->withdrawal_method_label,
                    'status' => $withdrawal->status,
                    'status_color' => $withdrawal->status_color,
                    'status_badge_color' => $withdrawal->status_badge_color,
                    'transaction_id' => $withdrawal->transaction_id,
                    'failure_reason' => $withdrawal->failure_reason,
                    'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'processed_at' => $withdrawal->processed_at?->format('Y-m-d H:i:s'),
                    'days_since_created' => $withdrawal->days_since_created,
                    'is_recent' => $withdrawal->is_recent,
                    'can_be_cancelled' => $withdrawal->canBeCancelled(),
                    'withdrawal_details' => $withdrawal->withdrawal_details,
                    'bank_account_info' => $withdrawal->bank_account_info,
                    'pix_info' => $withdrawal->pix_info,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal details', [
                'user_id' => $user->id,
                'withdrawal_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawal details',
            ], 500);
        }
    }

    /**
     * Cancel a withdrawal request
     */
    public function cancel(int $id): JsonResponse
    {
        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can cancel withdrawals',
            ], 403);
        }

        try {
            $withdrawal = Withdrawal::where('creator_id', $user->id)
                ->where('status', 'pending')
                ->find($id);

            if (!$withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal not found or cannot be cancelled',
                ], 404);
            }

            if (!$withdrawal->canBeCancelled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal cannot be cancelled',
                ], 400);
            }

            if ($withdrawal->cancel()) {
                Log::info('Withdrawal cancelled successfully', [
                    'withdrawal_id' => $withdrawal->id,
                    'creator_id' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Withdrawal cancelled successfully',
                    'data' => [
                        'id' => $withdrawal->id,
                        'status' => $withdrawal->status,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to cancel withdrawal',
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error cancelling withdrawal', [
                'user_id' => $user->id,
                'withdrawal_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel withdrawal. Please try again.',
            ], 500);
        }
    }

    /**
     * Get withdrawal statistics for the creator
     */
    public function statistics(): JsonResponse
    {
        $user = Auth::user();

        // Check if user is a creator or student
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can access withdrawal statistics',
            ], 403);
        }

        try {
            $withdrawals = $user->withdrawals();

            $stats = [
                'total_withdrawals' => $withdrawals->count(),
                'total_amount_withdrawn' => $withdrawals->where('status', 'completed')->sum('amount'),
                'pending_withdrawals' => $withdrawals->where('status', 'pending')->count(),
                'pending_amount' => $withdrawals->where('status', 'pending')->sum('amount'),
                'processing_withdrawals' => $withdrawals->where('status', 'processing')->count(),
                'processing_amount' => $withdrawals->where('status', 'processing')->sum('amount'),
                'failed_withdrawals' => $withdrawals->where('status', 'failed')->count(),
                'cancelled_withdrawals' => $withdrawals->where('status', 'cancelled')->count(),
                'this_month' => $withdrawals->where('status', 'completed')
                    ->whereMonth('processed_at', now()->month)
                    ->whereYear('processed_at', now()->year)
                    ->sum('amount'),
                'this_year' => $withdrawals->where('status', 'completed')
                    ->whereYear('processed_at', now()->year)
                    ->sum('amount'),
            ];

            // Format amounts
            $stats['formatted_total_amount_withdrawn'] = 'R$ ' . number_format($stats['total_amount_withdrawn'], 2, ',', '.');
            $stats['formatted_pending_amount'] = 'R$ ' . number_format($stats['pending_amount'], 2, ',', '.');
            $stats['formatted_processing_amount'] = 'R$ ' . number_format($stats['processing_amount'], 2, ',', '.');
            $stats['formatted_this_month'] = 'R$ ' . number_format($stats['this_month'], 2, ',', '.');
            $stats['formatted_this_year'] = 'R$ ' . number_format($stats['this_year'], 2, ',', '.');

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal statistics', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawal statistics',
            ], 500);
        }
    }
} 
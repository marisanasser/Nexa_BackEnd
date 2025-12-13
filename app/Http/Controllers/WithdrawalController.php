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
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;
use Stripe\Stripe;
use Stripe\Account;

class WithdrawalController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    
    private function checkStripePayoutsEnabled(User $user): array
    {
        try {
            Log::info('Checking Stripe payouts enabled status', [
                'user_id' => $user->id,
                'has_stripe_account_id' => !empty($user->stripe_account_id),
            ]);
            
            if (!$user->stripe_account_id) {
                Log::info('Stripe account not found for user', [
                    'user_id' => $user->id,
                ]);
                return [
                    'enabled' => false,
                    'message' => 'Você precisa configurar sua conta Stripe antes de solicitar saques. Acesse as configurações do Stripe para completar o cadastro.',
                    'action_required' => 'stripe_setup'
                ];
            }
            
            Log::info('Retrieving Stripe account from API', [
                'user_id' => $user->id,
                'stripe_account_id' => $user->stripe_account_id,
            ]);
            
            $stripeAccount = Account::retrieve($user->stripe_account_id);
            
            Log::info('Stripe account retrieved', [
                'user_id' => $user->id,
                'account_id' => $stripeAccount->id,
                'payouts_enabled' => $stripeAccount->payouts_enabled ?? false,
                'charges_enabled' => $stripeAccount->charges_enabled ?? false,
                'details_submitted' => $stripeAccount->details_submitted ?? false,
            ]);
            
            if (!$stripeAccount->payouts_enabled) {
                Log::warning('Stripe payouts not enabled for user', [
                    'user_id' => $user->id,
                    'account_id' => $stripeAccount->id,
                ]);
                return [
                    'enabled' => false,
                    'message' => 'Sua conta Stripe ainda não está habilitada para receber pagamentos. Complete o processo de verificação no Stripe para ativar os saques.',
                    'action_required' => 'stripe_verification'
                ];
            }
            
            Log::info('Stripe payouts enabled for user', [
                'user_id' => $user->id,
                'account_id' => $stripeAccount->id,
            ]);
            
            return [
                'enabled' => true,
                'message' => 'Conta Stripe configurada corretamente'
            ];

        } catch (\Exception $e) {
            Log::error('Error checking Stripe payouts status', [
                'user_id' => $user->id,
                'stripe_account_id' => $user->stripe_account_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'enabled' => false,
                'message' => 'Erro ao verificar status da conta Stripe. Tente novamente mais tarde.',
                'action_required' => 'retry'
            ];
        }
    }

    
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        
        Log::info('Withdrawal request initiated', [
            'user_id' => $user?->id,
            'has_user' => !is_null($user),
            'requested_amount' => $request->amount ?? 'not_provided',
            'withdrawal_method' => $request->withdrawal_method ?? 'not_provided',
        ]);
        
        if (!$user->isCreator() && !$user->isStudent()) {
            Log::warning('Withdrawal request denied: User is not creator or student', [
                'user_id' => $user->id,
                'user_role' => $user->role ?? 'unknown',
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can request withdrawals',
            ], 403);
        }
        
        Log::info('Checking Stripe payouts status for withdrawal', [
            'user_id' => $user->id,
        ]);
        
        
        $payoutsStatus = $this->checkStripePayoutsEnabled($user);
        if (!$payoutsStatus['enabled']) {
            Log::warning('Withdrawal request blocked: Stripe payouts not enabled', [
                'user_id' => $user->id,
                'action_required' => $payoutsStatus['action_required'],
            ]);
            return response()->json([
                'success' => false,
                'message' => $payoutsStatus['message'],
                'action_required' => $payoutsStatus['action_required'],
                'blocked' => true
            ], 403);
        }
        
        Log::info('Stripe payouts enabled, proceeding with withdrawal', [
            'user_id' => $user->id,
        ]);
        
        
        $withdrawalMethod = \App\Models\WithdrawalMethod::findByCode($request->withdrawal_method);
        $dynamicMethod = null;
        
        
        if (!$withdrawalMethod) {
            
            $availableMethods = $user->getWithdrawalMethods();
            
            
            if (strpos($request->withdrawal_method, 'stripe') !== false) {
                $dynamicMethod = $availableMethods->first(function ($method) use ($request, $user) {
                    return $method['id'] === $request->withdrawal_method &&
                           (isset($method['stripe_account_id']) || !empty($user->stripe_account_id));
                });
            } else {
                $dynamicMethod = $availableMethods->firstWhere('id', $request->withdrawal_method);
            }
            
            if (!$dynamicMethod) {
                Log::error('Invalid withdrawal method requested', [
                    'user_id' => $user->id,
                    'requested_method' => $request->withdrawal_method,
                    'has_stripe_account_id' => !empty($user->stripe_account_id),
                    'stripe_account_id' => $user->stripe_account_id,
                    'available_methods' => \App\Models\WithdrawalMethod::getActiveMethods()->pluck('code')->toArray(),
                    'user_available_methods' => $availableMethods->pluck('id')->toArray()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Método de saque inválido ou não disponível. ' . 
                                (strpos($request->withdrawal_method, 'stripe') !== false && !$user->stripe_account_id 
                                    ? 'Configure sua conta Stripe para usar métodos Stripe.' 
                                    : ''),
                ], 400);
            }
            
            Log::info('Dynamic withdrawal method validated', [
                'user_id' => $user->id,
                'method_code' => $request->withdrawal_method,
                'method_name' => $dynamicMethod['name'] ?? $request->withdrawal_method,
                'has_stripe_account_id' => isset($dynamicMethod['stripe_account_id']),
            ]);
        } else {
            Log::info('Withdrawal method validated', [
                'user_id' => $user->id,
                'method_code' => $withdrawalMethod->code,
                'method_name' => $withdrawalMethod->name,
            ]);
        }
        
        
        if (($request->withdrawal_method === 'stripe_connect_bank_account' || 
             $request->withdrawal_method === 'stripe_connect' ||
             $request->withdrawal_method === 'stripe_card') && 
            !$user->stripe_account_id) {
            return response()->json([
                'success' => false,
                'message' => 'Você precisa configurar sua conta Stripe antes de usar este método de saque.',
                'action_required' => 'stripe_setup'
            ], 400);
        }
        
        
        if ($dynamicMethod && 
            (strpos($request->withdrawal_method, 'stripe') !== false) &&
            empty($dynamicMethod['stripe_account_id']) &&
            !$user->stripe_account_id) {
            return response()->json([
                'success' => false,
                'message' => 'Método de saque Stripe requer uma conta Stripe configurada.',
                'action_required' => 'stripe_setup'
            ], 400);
        }
        
        
        $validationRules = [
            'amount' => 'required|numeric|min:0.01',
            'withdrawal_method' => 'required|string',
            'withdrawal_details' => 'nullable|array',
        ];

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

        
        
        
        
        
        
        
        
        
        

        try {
            
            return DB::transaction(function () use ($user, $request, $withdrawalMethod, $dynamicMethod) {
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

                
                if (!$balance->canWithdraw($request->amount)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Saldo insuficiente para o saque. Saldo disponível: ' . $balance->formatted_available_balance,
                    ], 400);
                }

                
                if ($withdrawalMethod) {
                    
                    if (!$withdrawalMethod->isAmountValid($request->amount)) {
                        return response()->json([
                            'success' => false,
                            'message' => "Valor deve estar entre {$withdrawalMethod->formatted_min_amount} e {$withdrawalMethod->formatted_max_amount} para {$withdrawalMethod->name}",
                        ], 400);
                    }
                } elseif ($dynamicMethod) {
                    
                    $minAmount = $dynamicMethod['min_amount'] ?? 0;
                    $maxAmount = $dynamicMethod['max_amount'] ?? 1000000;
                    if ($request->amount < $minAmount || $request->amount > $maxAmount) {
                        $formattedMin = 'R$ ' . number_format($minAmount, 2, ',', '.');
                        $formattedMax = 'R$ ' . number_format($maxAmount, 2, ',', '.');
                        $methodName = $dynamicMethod['name'] ?? $request->withdrawal_method;
                        return response()->json([
                            'success' => false,
                            'message' => "Valor deve estar entre {$formattedMin} e {$formattedMax} para {$methodName}",
                        ], 400);
                    }
                }

                
                $pendingWithdrawals = $user->withdrawals()
                    ->whereIn('status', ['pending', 'processing'])
                    ->count();

                if ($pendingWithdrawals >= 3) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Você tem muitos saques pendentes. Aguarde o processamento dos saques atuais.',
                    ], 400);
                }

                
                $withdrawalDetails = $request->withdrawal_details ?? [];
                
                
                if ($withdrawalMethod) {
                    $withdrawalDetails['method_fee_percentage'] = $withdrawalMethod->fee;
                    $withdrawalDetails['method_name'] = $withdrawalMethod->name;
                    $withdrawalDetails['method_code'] = $withdrawalMethod->code;
                } elseif ($dynamicMethod) {
                    $withdrawalDetails['method_fee_percentage'] = $dynamicMethod['fee'] ?? 0;
                    $withdrawalDetails['method_name'] = $dynamicMethod['name'] ?? $request->withdrawal_method;
                    $withdrawalDetails['method_code'] = $request->withdrawal_method;
                }
                
                
                if (strpos($request->withdrawal_method, 'stripe') !== false && $user->stripe_account_id) {
                    
                    if ($dynamicMethod && isset($dynamicMethod['stripe_account_id'])) {
                        $withdrawalDetails['stripe_account_id'] = $dynamicMethod['stripe_account_id'];
                    } else {
                        $withdrawalDetails['stripe_account_id'] = $user->stripe_account_id;
                    }
                }
                
                
                if (($request->withdrawal_method === 'stripe_connect_bank_account' || 
                     ($withdrawalMethod && $withdrawalMethod->code === 'stripe_connect_bank_account')) 
                    && $user->stripe_account_id) {
                    $withdrawalDetails['stripe_account_id'] = $user->stripe_account_id;
                    
                    
                    try {
                        $stripeSecret = config('services.stripe.secret');
                        if ($stripeSecret) {
                            \Stripe\Stripe::setApiKey($stripeSecret);
                            
                            
                            $externalAccounts = \Stripe\Account::allExternalAccounts(
                                $user->stripe_account_id,
                                ['object' => 'bank_account', 'limit' => 1]
                            );
                            
                            if (!empty($externalAccounts->data)) {
                                $bankAccount = $externalAccounts->data[0];
                                $withdrawalDetails['bank_account_id'] = $bankAccount->id;
                                $withdrawalDetails['bank_name'] = $bankAccount->bank_name ?? null;
                                $withdrawalDetails['bank_last4'] = $bankAccount->last4 ?? null;
                                $withdrawalDetails['account_holder_name'] = $bankAccount->account_holder_name ?? null;
                                $withdrawalDetails['account_holder_type'] = $bankAccount->account_holder_type ?? null;
                                $withdrawalDetails['country'] = $bankAccount->country ?? 'BR';
                                $withdrawalDetails['currency'] = $bankAccount->currency ?? 'brl';
                                $withdrawalDetails['routing_number'] = $bankAccount->routing_number ?? null;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to retrieve Stripe Connect bank account details for withdrawal', [
                            'user_id' => $user->id,
                            'stripe_account_id' => $user->stripe_account_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                
                if (($withdrawalMethod && $withdrawalMethod->code === 'pagarme_bank_transfer') || 
                    $request->withdrawal_method === 'pagarme_bank_transfer') {
                    $bankAccount = \App\Models\BankAccount::where('user_id', $user->id)->first();
                    if ($bankAccount) {
                        $withdrawalDetails = array_merge($withdrawalDetails, [
                            'bank_code' => $bankAccount->bank_code,
                            'agencia' => $bankAccount->agencia,
                            'agencia_dv' => $bankAccount->agencia_dv,
                            'conta' => $bankAccount->conta,
                            'conta_dv' => $bankAccount->conta_dv,
                            'cpf' => $bankAccount->cpf,
                            'name' => $bankAccount->name,
                        ]);
                    }
                }

                
                $withdrawal = Withdrawal::create([
                    'creator_id' => $user->id,
                    'amount' => $request->amount,
                    'platform_fee' => 5.00, 
                    'fixed_fee' => 5.00, 
                    'withdrawal_method' => $request->withdrawal_method,
                    'withdrawal_details' => $withdrawalDetails,
                    'status' => 'pending',
                ]);

                
                if (!$withdrawal || !$withdrawal->id) {
                    throw new \Exception('Failed to create withdrawal record in database');
                }

                
                $withdrawResult = $balance->withdraw($request->amount);
                
                if (!$withdrawResult) {
                    throw new \Exception('Failed to deduct amount from available balance');
                }
                
                
                $balance->refresh();

                $methodName = $withdrawalMethod ? $withdrawalMethod->name : ($dynamicMethod['name'] ?? $request->withdrawal_method);
                
                Log::info('Withdrawal request created and stored successfully in database', [
                    'withdrawal_id' => $withdrawal->id,
                    'creator_id' => $user->id,
                    'amount' => $request->amount,
                    'formatted_amount' => $withdrawal->formatted_amount,
                    'method' => $request->withdrawal_method,
                    'method_name' => $methodName,
                    'status' => $withdrawal->status,
                    'net_amount' => $withdrawal->net_amount,
                    'total_fees' => $withdrawal->total_fees,
                    'created_at' => $withdrawal->created_at->toDateTimeString(),
                    'has_stripe_payment_method' => isset($withdrawalDetails['stripe_payment_method_id']),
                    'has_stripe_account_id' => isset($withdrawalDetails['stripe_account_id']),
                    'stripe_account_id' => $withdrawalDetails['stripe_account_id'] ?? null,
                    'is_dynamic_method' => !is_null($dynamicMethod),
                    'balance_after_withdrawal' => [
                        'available_balance' => $balance->available_balance,
                        'total_withdrawn' => $balance->total_withdrawn,
                    ],
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
            });

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

    
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        
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

    
    public function show(int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        
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

    
    public function cancel(int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        
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

    
    public function statistics(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        
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
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Domain\Payment\Actions\CreateWithdrawalAction;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Http\Resources\Payment\WithdrawalCollection;
use App\Http\Resources\Payment\WithdrawalResource;
use App\Models\Payment\Withdrawal;
use App\Models\Payment\WithdrawalMethod;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WithdrawalController extends Controller
{
    use HasAuthenticatedUser;

    public function __construct(
        private readonly StripeWrapper $stripe
    ) {
        $this->stripe->setApiKey((string) config('services.stripe.secret'));
    }

    public function store(Request $request, CreateWithdrawalAction $createAction): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        Log::info('Withdrawal request initiated', [
            'user_id' => $user?->id,
            'requested_amount' => $request->input('amount', 'not_provided'),
            'withdrawal_method' => $request->input('withdrawal_method', 'not_provided'),
        ]);

        // Authorization check
        if (!$user->isCreator() && !$user->isStudent()) {
            Log::warning('Withdrawal request denied: User is not creator or student', [
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can request withdrawals',
            ], 403);
        }

        // Check Stripe payouts status
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
                'blocked' => true,
            ], 403);
        }

        // Basic validation
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'withdrawal_method' => 'required|string',
            'withdrawal_details' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $withdrawalMethodCode = $request->string('withdrawal_method')->toString();
        $amount = $request->float('amount');

        // Validate and get withdrawal method
        $methodValidation = $this->validateWithdrawalMethod($user, $withdrawalMethodCode);
        if (!$methodValidation['valid']) {
            return response()->json([
                'success' => false,
                'message' => $methodValidation['message'],
                'action_required' => $methodValidation['action_required'] ?? null,
            ], 400);
        }

        $withdrawalMethod = $methodValidation['withdrawalMethod'];
        $dynamicMethod = $methodValidation['dynamicMethod'];

        // Execute the withdrawal creation via Action
        $result = $createAction->execute(
            user: $user,
            amount: $amount,
            withdrawalMethodCode: $withdrawalMethodCode,
            withdrawalMethod: $withdrawalMethod,
            dynamicMethod: $dynamicMethod,
            withdrawalDetails: $request->input('withdrawal_details', [])
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request submitted successfully',
            'data' => WithdrawalResource::forCreation($result['withdrawal']),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

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

            $withdrawals = $query->orderBy('created_at', 'desc')->paginate(10);

            return (new WithdrawalCollection($withdrawals))->response();
        } catch (Exception $e) {
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
        $user = $this->getAuthenticatedUser();

        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can access withdrawal details',
            ], 403);
        }

        try {
            $withdrawal = $user->withdrawals()->find($id);

            if (!$withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new WithdrawalResource($withdrawal),
            ]);
        } catch (Exception $e) {
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
        $user = $this->getAuthenticatedUser();

        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can cancel withdrawals',
            ], 403);
        }

        try {
            $withdrawal = Withdrawal::where('creator_id', $user->id)
                ->where('status', 'pending')
                ->find($id)
            ;

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
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel withdrawal',
            ], 500);
        } catch (Exception $e) {
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
        $user = $this->getAuthenticatedUser();

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
        } catch (Exception $e) {
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
                    'action_required' => 'stripe_setup',
                ];
            }

            Log::info('Retrieving Stripe account from API', [
                'user_id' => $user->id,
                'stripe_account_id' => $user->stripe_account_id,
            ]);

            $stripeAccount = $this->stripe->retrieveAccount($user->stripe_account_id);

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
                    'action_required' => 'stripe_verification',
                ];
            }

            Log::info('Stripe payouts enabled for user', [
                'user_id' => $user->id,
                'account_id' => $stripeAccount->id,
            ]);

            return [
                'enabled' => true,
                'message' => 'Conta Stripe configurada corretamente',
            ];
        } catch (Exception $e) {
            Log::error('Error checking Stripe payouts status', [
                'user_id' => $user->id,
                'stripe_account_id' => $user->stripe_account_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'enabled' => false,
                'message' => 'Erro ao verificar status da conta Stripe. Tente novamente mais tarde.',
                'action_required' => 'retry',
            ];
        }
    }

    /**
     * Validate withdrawal method and return method data.
     *
     * @return array{valid: bool, message?: string, action_required?: string, withdrawalMethod?: ?WithdrawalMethod, dynamicMethod?: ?array}
     */
    private function validateWithdrawalMethod(User $user, string $withdrawalMethodCode): array
    {
        $withdrawalMethod = WithdrawalMethod::findByCode($withdrawalMethodCode);
        $dynamicMethod = null;

        if (!$withdrawalMethod) {
            $availableMethods = $user->getWithdrawalMethods();

            $dynamicMethod = str_contains($withdrawalMethodCode, 'stripe')
                ? $availableMethods->first(fn($method) => $method['id'] === $withdrawalMethodCode
                    && (isset($method['stripe_account_id']) || !empty($user->stripe_account_id)))
                : $availableMethods->firstWhere('id', $withdrawalMethodCode);

            if (!$dynamicMethod) {
                Log::error('Invalid withdrawal method requested', [
                    'user_id' => $user->id,
                    'requested_method' => $withdrawalMethodCode,
                ]);

                $message = 'Método de saque inválido ou não disponível.';
                if (str_contains($withdrawalMethodCode, 'stripe') && !$user->stripe_account_id) {
                    $message .= ' Configure sua conta Stripe para usar métodos Stripe.';
                }

                return ['valid' => false, 'message' => $message];
            }
        }

        // Check Stripe account requirement
        $stripeMethodsRequiringAccount = ['stripe_connect_bank_account', 'stripe_connect', 'stripe_card'];
        if (in_array($withdrawalMethodCode, $stripeMethodsRequiringAccount) && !$user->stripe_account_id) {
            return [
                'valid' => false,
                'message' => 'Você precisa configurar sua conta Stripe antes de usar este método de saque.',
                'action_required' => 'stripe_setup',
            ];
        }

        if (
            $dynamicMethod
            && str_contains($withdrawalMethodCode, 'stripe')
            && empty($dynamicMethod['stripe_account_id'])
            && !$user->stripe_account_id
        ) {
            return [
                'valid' => false,
                'message' => 'Método de saque Stripe requer uma conta Stripe configurada.',
                'action_required' => 'stripe_setup',
            ];
        }

        return [
            'valid' => true,
            'withdrawalMethod' => $withdrawalMethod,
            'dynamicMethod' => $dynamicMethod,
        ];
    }
}

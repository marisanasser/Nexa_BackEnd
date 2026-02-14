<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use App\Domain\Payment\Services\ContractPaymentService;
use App\Domain\Payment\Services\StripeCustomerService;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Models\Contract\Contract;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class ContractCheckoutController extends Controller
{
    use HasAuthenticatedUser;

    private ?string $stripeKey = null;

    public function __construct(
        private readonly ContractPaymentService $paymentService,
        private readonly StripeCustomerService $customerService
    ) {
        $this->stripeKey = config('services.stripe.secret');
    }

    public function createContractCheckoutSession(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can create contract checkout sessions',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'contract_id' => "required|exists:contracts,id,brand_id,{$user->id}",
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $contract = Contract::with(['brand', 'creator', 'offer.chatRoom'])->find($request->contract_id);

            if ('pending' !== $contract->status || 'payment_pending' !== $contract->workflow_status) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contract is not in a state that requires payment',
                ], 400);
            }

            if ($contract->payment && 'completed' === $contract->payment->status) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment for this contract has already been processed',
                ], 400);
            }

            if (!$this->stripeKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured',
                ], 503);
            }

            Stripe::setApiKey($this->stripeKey);

            $customerId = $this->customerService->ensureStripeCustomer($user);
            $frontendUrl = (string) config('app.frontend_url', 'http://localhost:5000');
            $roomId = $contract->offer?->chatRoom?->room_id;
            $messagesUrl = "{$frontendUrl}/dashboard/messages";

            if ($roomId) {
                $messagesUrl .= '?roomId=' . rawurlencode($roomId);
            }

            $separator = str_contains($messagesUrl, '?') ? '&' : '?';
            $successUrl = "{$messagesUrl}{$separator}funding_success=true&session_id={CHECKOUT_SESSION_ID}&contract_id={$contract->id}";
            $cancelUrl = "{$messagesUrl}{$separator}funding_canceled=true&contract_id={$contract->id}";

            $checkoutSession = $this->paymentService->createContractFundingCheckout($contract, $user, $successUrl, $cancelUrl);

            Log::info('Contract funding checkout session created', [
                'session_id' => $checkoutSession->id,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'contract_id' => $contract->id,
                'amount' => $contract->budget,
            ]);

            return response()->json([
                'success' => true,
                'url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to create contract funding checkout session', [
                'user_id' => auth()->id(),
                'contract_id' => $request->integer('contract_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function handleFundingSuccess(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            $validator = Validator::make($request->all(), [
                'session_id' => 'required|string',
                'contract_id' => "required|integer|exists:contracts,id,brand_id,{$user->id}",
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if (!$this->stripeKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured',
                ], 503);
            }

            Stripe::setApiKey($this->stripeKey);
            $contract = Contract::findOrFail($request->integer('contract_id'));

            $session = Session::retrieve($request->string('session_id')->toString());

            // Handle funding success via service
            $payment = $this->paymentService->handleContractFundingSuccess($contract, $session);

            Log::info('Contract funding handled successfully from frontend redirect', [
                'contract_id' => $contract->id,
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'amount' => $payment->total_amount,
            ]);

            $contract->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Contract funding confirmed',
                'payment_status' => $payment->status,
                'contract_status' => $contract->status,
            ]);
        } catch (Exception $e) {
            Log::error('Error handling funding success', [
                'user_id' => auth()->id(),
                'contract_id' => $request->integer('contract_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm funding. Please check the contract status or contact support.',
            ], 500);
        }
    }
}

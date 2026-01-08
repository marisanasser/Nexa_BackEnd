<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Domain\Payment\Services\CreatorBalanceService;
use App\Domain\Shared\Traits\HasApiResponses;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorBalanceController extends Controller
{
    use HasApiResponses;
    use HasAuthenticatedUser;

    public function __construct(
        private readonly CreatorBalanceService $balanceService
    ) {}

    /**
     * Get creator balance summary.
     */
    public function index(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (!$user->isCreator() && !$user->isStudent()) {
            return $this->errorResponse('Access denied', 403);
        }

        try {
            $balance = $this->balanceService->ensureBalanceExists($user);
            $summary = $this->balanceService->getBalanceSummary($user);

            // Get recent transactions manually for now as service doesn't have it yet encapsulated perfectly with exact format
            // But we can retrieve them from relations via balance object returned
            $recentTransactions = $balance->payments()
                ->with('contract:id,title')
                ->where('status', 'completed')
                ->orderBy('processed_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn ($payment) => [
                    'id' => $payment->id,
                    'contract_title' => $payment->contract->title,
                    'amount' => $payment->formatted_creator_amount,
                    'status' => $payment->status,
                    'processed_at' => $payment->processed_at?->format('Y-m-d H:i:s'),
                ])
            ;

            $recentWithdrawals = collect($this->balanceService->getWithdrawalHistory($user, 5)->items())->map(fn ($withdrawal) => [
                'id' => $withdrawal->id,
                'amount' => $withdrawal->formatted_amount,
                'method' => $withdrawal->withdrawal_method_label,
                'status' => $withdrawal->status,
                'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
            ]);

            // Earnings stats (keep logic from model access)
            $earnings = [
                'this_month' => (float) $balance->earnings_this_month,
                'this_year' => (float) $balance->earnings_this_year,
                'formatted_this_month' => $balance->formatted_earnings_this_month,
                'formatted_this_year' => $balance->formatted_earnings_this_year,
            ];

            return $this->successResponse([
                'balance' => [
                    'available_balance' => $summary['available_balance'],
                    'pending_balance' => $summary['pending_balance'],
                    'total_balance' => (float) $balance->total_balance, // logic in model accessor
                    'total_earned' => $summary['total_earned'],
                    'total_withdrawn' => $summary['total_withdrawn'],
                    'formatted_available_balance' => $balance->formatted_available_balance,
                    'formatted_pending_balance' => $balance->formatted_pending_balance,
                    'formatted_total_balance' => $balance->formatted_total_balance,
                    'formatted_total_earned' => $balance->formatted_total_earned,
                    'formatted_total_withdrawn' => $balance->formatted_total_withdrawn,
                ],
                'earnings' => $earnings,
                'withdrawals' => [
                    'pending_count' => (int) $balance->pending_withdrawals_count,
                    'pending_amount' => (float) $balance->pending_withdrawals_amount,
                    'formatted_pending_amount' => $balance->formatted_pending_withdrawals_amount,
                ],
                'recent_transactions' => $recentTransactions,
                'recent_withdrawals' => $recentWithdrawals,
            ], 'Balance retrieved successfully');
        } catch (Exception $e) {
            Log::error('Balance fetch error', ['error' => $e->getMessage()]);

            return $this->errorResponse('Failed to fetch balance', 500);
        }
    }

    /**
     * Get balance history (earnings and withdrawals).
     */
    public function history(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (!$user->isCreator() && !$user->isStudent()) {
            return $this->errorResponse('Access denied', 403);
        }

        if ($user->isStudent()) {
            return $this->successResponse([
                'history' => [],
                'summary' => ['total_earnings' => 0, 'total_withdrawals' => 0, 'net_balance' => 0],
            ]);
        }

        $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
            'type' => 'nullable|in:earnings,withdrawals,all',
        ]);

        try {
            $balance = $this->balanceService->ensureBalanceExists($user);
            $days = $request->get('days', 30);
            $type = $request->get('type', 'all');

            // Reusing logic from old controller but encapsulated slightly better
            // Ideally this logic moves to Service entirely "getUnifiedHistory"
            $history = [];

            if ('all' === $type || 'earnings' === $type) {
                $earnings = $balance->payments()
                    ->with('contract:id,title')
                    ->where('status', 'completed')
                    ->when($days < 365, fn ($q) => $q->where('processed_at', '>=', now()->subDays($days)))
                    ->orderBy('processed_at', 'desc')
                    ->get()
                    ->map(fn ($p) => [
                        'type' => 'earning',
                        'id' => $p->id,
                        'amount' => $p->creator_amount,
                        'formatted_amount' => $p->formatted_creator_amount,
                        'description' => 'Payment for: '.($p->contract->title ?? 'Contract'),
                        'date' => $p->processed_at->format('Y-m-d H:i:s'),
                        'status' => $p->status,
                    ])
                ;
                $history = array_merge($history, $earnings->toArray());
            }

            if ('all' === $type || 'withdrawals' === $type) {
                $withdrawals = $balance->withdrawals()
                    ->when($days < 365, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(fn ($w) => [
                        'type' => 'withdrawal',
                        'id' => $w->id,
                        'amount' => -$w->amount,
                        'formatted_amount' => '-'.$w->formatted_amount,
                        'description' => 'Withdrawal via '.$w->withdrawal_method_label,
                        'date' => $w->created_at->format('Y-m-d H:i:s'),
                        'status' => $w->status,
                    ])
                ;
                $history = array_merge($history, $withdrawals->toArray());
            }

            // Sort by date desc
            usort($history, fn ($a, $b) => strtotime($b['date']) - strtotime($a['date']));

            // Calc running balance
            $runningBalance = 0;
            // Iterate reverse to calculate running balance correctly forward in time?
            // Actually usually running balance is calculated from beginning.
            // Old controller logic: iterate reverse history, sum running balance.
            // Let's mimic old logic to ensure frontend consistency.

            $reversedHistory = array_reverse($history);
            foreach ($reversedHistory as &$item) {
                $runningBalance += $item['amount'];
                $item['running_balance'] = $runningBalance;
                $item['formatted_running_balance'] = 'R$ '.number_format($runningBalance, 2, ',', '.');
            }
            $historyByDateDesc = array_reverse($reversedHistory);

            return $this->successResponse([
                'history' => $historyByDateDesc,
                'summary' => [
                    'total_earnings' => $balance->total_earned,
                    'total_withdrawals' => $balance->total_withdrawn,
                    'current_balance' => $balance->total_balance,
                    'formatted_total_earnings' => $balance->formatted_total_earned,
                    'formatted_total_withdrawals' => $balance->formatted_total_withdrawn,
                    'formatted_current_balance' => $balance->formatted_total_balance,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch history: '.$e->getMessage(), 500);
        }
    }

    /**
     * Get available withdrawal methods.
     */
    public function withdrawalMethods(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user->isCreator() && !$user->isStudent()) {
            return $this->errorResponse('Access denied', 403);
        }

        try {
            $methods = $this->balanceService->getWithdrawalMethods($user);

            return $this->successResponse($methods);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch methods', 500);
        }
    }

    /**
     * Get work history.
     */
    public function workHistory(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user->isCreator() && !$user->isStudent()) {
            return $this->errorResponse('Access denied', 403);
        }

        if ($user->isStudent()) {
            return $this->successResponse(['data' => [], 'meta' => []]); // Simplified pagination
        }

        try {
            // Using service method but doing transformation here or in service?
            // Service returns Paginator.
            $paginator = $this->balanceService->getWorkHistory($user);

            // Transform logic from old controller
            $paginator = $paginator->through(fn ($contract) => [
                'id' => $contract->id,
                'title' => $contract->title,
                'description' => $contract->description,
                'budget' => $contract->formatted_budget,
                'creator_amount' => $contract->formatted_creator_amount,
                'status' => $contract->status,
                'started_at' => $contract->started_at->format('Y-m-d H:i:s'),
                'completed_at' => $contract->completed_at?->format('Y-m-d H:i:s'),
                'brand' => [
                    'id' => $contract->brand->id,
                    'name' => $contract->brand->name,
                    'avatar_url' => $contract->brand->avatar_url,
                ],
                'payment' => $contract->payment ? [
                    'status' => $contract->payment->status,
                    'amount' => $contract->payment->formatted_creator_amount,
                    'processed_at' => $contract->payment->processed_at?->format('Y-m-d H:i:s'),
                ] : null,
                'review' => ($userReview = $contract->userReview($user->id)->first()) ? [
                    'rating' => $userReview->rating,
                    'comment' => $userReview->comment,
                    'created_at' => $userReview->created_at->format('Y-m-d H:i:s'),
                ] : null,
            ]);

            return $this->successResponse($paginator);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch work history', 500);
        }
    }

    /**
     * Create Stripe Setup Checkout.
     */
    public function createStripePaymentMethodCheckout(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user->isCreator() && !$user->isStudent()) {
            return $this->errorResponse('Access denied', 403);
        }

        try {
            $data = $this->balanceService->createStripeSetupSession($user);

            return $this->successResponse($data);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Handle success callback from Stripe.
     */
    public function handleCheckoutSuccess(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $sessionId = $request->input('session_id') ?? $request->query('session_id');

        if (!$sessionId) {
            return $this->errorResponse('Session ID required', 400);
        }

        try {
            $data = $this->balanceService->saveStripePaymentMethod($user, $sessionId);

            return $this->successResponse($data, 'Payment method connected successfully');
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}

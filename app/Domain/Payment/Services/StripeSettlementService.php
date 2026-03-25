<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\Payment\JobPayment;
use App\Models\Payment\Transaction;
use App\Models\Payment\Withdrawal;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Transfer;

class StripeSettlementService
{
    public function __construct()
    {
        $secret = config('services.stripe.secret');
        if (!empty($secret)) {
            Stripe::setApiKey((string) $secret);
        }
    }

    /**
     * @return array{
     *   creator_id:int,
     *   settled_backed_cents:int,
     *   pending_reserved_cents:int,
     *   available_for_new_withdrawal_cents:int,
     *   max_single_source_cents:int,
     *   candidates:array<int,array<string,mixed>>
     * }
     */
    public function summarizeCreatorSettledFunds(int $creatorId, bool $applyPendingReservations = true): array
    {
        $jobPayments = JobPayment::query()
            ->where('creator_id', $creatorId)
            ->where('status', 'completed')
            ->whereNotNull('transaction_id')
            ->get(['id', 'transaction_id', 'creator_amount', 'processed_at'])
        ;

        $numericTxIds = $jobPayments
            ->filter(fn(JobPayment $payment) => is_numeric($payment->transaction_id))
            ->map(fn(JobPayment $payment) => (int) $payment->transaction_id)
            ->unique()
            ->values()
        ;

        if ($numericTxIds->isEmpty()) {
            return [
                'creator_id' => $creatorId,
                'settled_backed_cents' => 0,
                'pending_reserved_cents' => 0,
                'available_for_new_withdrawal_cents' => 0,
                'max_single_source_cents' => 0,
                'candidates' => [],
            ];
        }

        $transactions = Transaction::query()
            ->whereIn('id', $numericTxIds)
            ->whereIn('status', ['paid', 'succeeded'])
            ->whereNotNull('stripe_payment_intent_id')
            ->get(['id', 'status', 'stripe_payment_intent_id', 'paid_at'])
        ;

        $creatorAmountByTx = $jobPayments
            ->filter(fn(JobPayment $payment) => is_numeric($payment->transaction_id))
            ->groupBy(fn(JobPayment $payment) => (int) $payment->transaction_id)
            ->map(fn(Collection $items) => (float) $items->sum(fn(JobPayment $payment) => (float) ($payment->creator_amount ?? 0)))
        ;

        $usedByCharge = $this->getUsedTransferAmountsBySourceCharge();

        $nowUtc = Carbon::now('UTC');
        $settledBackedCents = 0;
        $maxSingleSourceCents = 0;
        $candidates = [];

        foreach ($transactions as $transaction) {
            $paymentIntentId = (string) ($transaction->stripe_payment_intent_id ?? '');
            if ('' === $paymentIntentId || !str_starts_with($paymentIntentId, 'pi_')) {
                continue;
            }

            $chargeData = $this->fetchPaymentIntentChargeData($paymentIntentId, $nowUtc);
            if (!$chargeData) {
                continue;
            }

            $usedCents = $usedByCharge[$chargeData['charge_id']] ?? 0;
            $availableChargeCents = max(0, $chargeData['charge_amount_cents'] - $usedCents);

            $creatorEntitledCents = (int) round(((float) ($creatorAmountByTx[(int) $transaction->id] ?? 0)) * 100);
            $availableEntitledCents = max(0, min($availableChargeCents, $creatorEntitledCents));

            if ($chargeData['is_settled']) {
                $settledBackedCents += $availableEntitledCents;
                $maxSingleSourceCents = max($maxSingleSourceCents, $availableEntitledCents);
            }

            $candidates[] = [
                'transaction_id' => (int) $transaction->id,
                'payment_intent_id' => $paymentIntentId,
                'charge_id' => $chargeData['charge_id'],
                'charge_amount_cents' => $chargeData['charge_amount_cents'],
                'creator_entitled_cents' => $creatorEntitledCents,
                'used_by_transfers_cents' => $usedCents,
                'available_entitled_cents' => $availableEntitledCents,
                'is_settled' => $chargeData['is_settled'],
                'balance_transaction_status' => $chargeData['balance_transaction_status'],
                'available_on_utc' => $chargeData['available_on_utc'],
                'transaction_paid_at' => $transaction->paid_at?->toDateTimeString(),
            ];
        }

        $pendingReservedCents = 0;
        if ($applyPendingReservations) {
            $pendingReservedCents = (int) round(
                ((float) Withdrawal::query()
                    ->where('creator_id', $creatorId)
                    ->whereIn('status', ['pending', 'processing'])
                    ->sum('amount')) * 100
            );
        }

        $availableForNewWithdrawalCents = max(0, $settledBackedCents - $pendingReservedCents);
        $effectiveMaxSingleSourceCents = max(0, $maxSingleSourceCents - $pendingReservedCents);

        return [
            'creator_id' => $creatorId,
            'settled_backed_cents' => $settledBackedCents,
            'pending_reserved_cents' => $pendingReservedCents,
            'available_for_new_withdrawal_cents' => $availableForNewWithdrawalCents,
            'max_single_source_cents' => $effectiveMaxSingleSourceCents,
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array{
     *   can_cover:bool,
     *   amount_cents:int,
     *   reason:string,
     *   summary:array<string,mixed>
     * }
     */
    public function canCoverWithdrawalAmount(int $creatorId, int $amountCents): array
    {
        $summary = $this->summarizeCreatorSettledFunds($creatorId, true);

        if ($summary['available_for_new_withdrawal_cents'] < $amountCents) {
            return [
                'can_cover' => false,
                'amount_cents' => $amountCents,
                'reason' => 'total_settled_funds_insufficient',
                'summary' => $summary,
            ];
        }

        if ($summary['max_single_source_cents'] < $amountCents) {
            return [
                'can_cover' => false,
                'amount_cents' => $amountCents,
                'reason' => 'no_single_settled_source_can_cover',
                'summary' => $summary,
            ];
        }

        return [
            'can_cover' => true,
            'amount_cents' => $amountCents,
            'reason' => 'ok',
            'summary' => $summary,
        ];
    }

    /**
     * @return null|array{charge_id:string,available_entitled_cents:int,payment_intent_id:string,transaction_id:int}
     */
    public function findSourceChargeForWithdrawal(int $creatorId, int $requiredAmountCents): ?array
    {
        $summary = $this->summarizeCreatorSettledFunds($creatorId, false);

        $eligible = collect($summary['candidates'])
            ->filter(fn(array $candidate) => true === $candidate['is_settled'])
            ->filter(fn(array $candidate) => (int) ($candidate['available_entitled_cents'] ?? 0) >= $requiredAmountCents)
            ->sortByDesc(fn(array $candidate) => (int) ($candidate['available_entitled_cents'] ?? 0))
            ->values()
            ->first()
        ;

        if (!$eligible) {
            return null;
        }

        return [
            'charge_id' => (string) $eligible['charge_id'],
            'available_entitled_cents' => (int) $eligible['available_entitled_cents'],
            'payment_intent_id' => (string) $eligible['payment_intent_id'],
            'transaction_id' => (int) $eligible['transaction_id'],
        ];
    }

    /**
     * @return array<string,int>
     */
    private function getUsedTransferAmountsBySourceCharge(): array
    {
        $used = [];
        $params = ['limit' => 100];
        $pages = 0;

        try {
            do {
                $pages++;
                $result = Transfer::all($params);
                $items = $result->data ?? [];

                foreach ($items as $transfer) {
                    $sourceChargeId = (string) ($transfer->source_transaction ?? '');
                    if ('' === $sourceChargeId) {
                        continue;
                    }

                    $used[$sourceChargeId] = ($used[$sourceChargeId] ?? 0) + (int) ($transfer->amount ?? 0);
                }

                if (!$result->has_more || empty($items) || $pages >= 50) {
                    break;
                }

                $last = end($items);
                if (!isset($last->id)) {
                    break;
                }

                $params['starting_after'] = $last->id;
            } while (true);
        } catch (Exception $e) {
            Log::warning('Unable to list Stripe transfers while calculating settlement backing', [
                'error' => $e->getMessage(),
            ]);
        }

        return $used;
    }

    /**
     * @return null|array{
     *   charge_id:string,
     *   charge_amount_cents:int,
     *   is_settled:bool,
     *   balance_transaction_status:null|string,
     *   available_on_utc:null|string
     * }
     */
    private function fetchPaymentIntentChargeData(string $paymentIntentId, Carbon $nowUtc): ?array
    {
        try {
            $intent = PaymentIntent::retrieve($paymentIntentId, [
                'expand' => ['latest_charge.balance_transaction'],
            ]);
        } catch (Exception $e) {
            Log::warning('Unable to fetch payment intent during settlement validation', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $latestCharge = $intent->latest_charge ?? null;
        if (!is_object($latestCharge) || empty($latestCharge->id)) {
            return null;
        }

        $balanceTx = $latestCharge->balance_transaction ?? null;
        $status = is_object($balanceTx) ? (string) ($balanceTx->status ?? '') : '';
        $availableOn = is_object($balanceTx) ? (int) ($balanceTx->available_on ?? 0) : 0;
        $availableOnUtc = $availableOn > 0 ? Carbon::createFromTimestampUTC($availableOn)->toDateTimeString() : null;

        $isSettled = false;
        if ('available' === $status) {
            $isSettled = true;
        } elseif ($availableOn > 0) {
            $isSettled = $availableOn <= $nowUtc->timestamp;
        }

        return [
            'charge_id' => (string) $latestCharge->id,
            'charge_amount_cents' => (int) ($latestCharge->amount ?? 0),
            'is_settled' => $isSettled,
            'balance_transaction_status' => '' !== $status ? $status : null,
            'available_on_utc' => $availableOnUtc,
        ];
    }
}

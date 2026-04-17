<?php

declare(strict_types=1);

namespace App\Console\Commands\Payment;

use App\Models\Payment\CreatorBalance;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FixPayoutCompliance extends Command
{
    protected $signature = 'payments:fix-payout-compliance
                            {--apply : Apply changes in database (default is dry-run)}
                            {--recalculate-balances : Recalculate creator balances after repairs}
                            {--output= : Optional absolute or storage-relative JSON output path}';

    protected $description = 'Repair payout/withdrawal consistency: fee percent, duplicate payments, legacy transaction links and overdrawn legacy withdrawals';

    private const float EXPECTED_FEE_PERCENT = 0.05;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $recalculateBalances = (bool) $this->option('recalculate-balances');

        $report = [
            'generated_at' => now()->toDateTimeString(),
            'mode' => $apply ? 'apply' : 'dry_run',
            'recalculate_balances' => $recalculateBalances,
            'fee_percent_expected' => self::EXPECTED_FEE_PERCENT * 100,
            'duplicates' => [],
            'job_payment_fee_fixes' => [],
            'contract_fee_fixes' => [],
            'transaction_link_repairs' => [],
            'legacy_withdrawal_earnings_repairs' => [],
            'balance_recalculations' => [],
            'post_summary' => [],
        ];

        try {
            if ($apply) {
                DB::transaction(function () use (&$report, $recalculateBalances): void {
                    $report['duplicates'] = $this->repairDuplicateCompletedJobPayments(true);
                    $report['job_payment_fee_fixes'] = $this->repairJobPaymentFeePercent(true);
                    $report['contract_fee_fixes'] = $this->repairContractFeePercent(true);
                    $report['transaction_link_repairs'] = $this->repairLegacyTransactionLinks(true);
                    $report['legacy_withdrawal_earnings_repairs'] = $this->repairLegacyWithdrawalEarnings(true);

                    if ($recalculateBalances) {
                        $report['balance_recalculations'] = $this->recalculateCreatorBalances(true);
                    }
                });
            } else {
                $report['duplicates'] = $this->repairDuplicateCompletedJobPayments(false);
                $report['job_payment_fee_fixes'] = $this->repairJobPaymentFeePercent(false);
                $report['contract_fee_fixes'] = $this->repairContractFeePercent(false);
                $report['transaction_link_repairs'] = $this->repairLegacyTransactionLinks(false);
                $report['legacy_withdrawal_earnings_repairs'] = $this->repairLegacyWithdrawalEarnings(false);

                if ($recalculateBalances) {
                    $report['balance_recalculations'] = $this->recalculateCreatorBalances(false);
                }
            }
        } catch (Throwable $e) {
            $this->error('Repair failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $report['post_summary'] = $this->buildPostSummary();
        $reportPath = $this->persistReport($report);

        $this->newLine();
        $this->info('Payout compliance repair finished.');
        $this->line("Mode: {$report['mode']}");
        $this->line("Report: {$reportPath}");
        $this->line('Duplicate rows handled: ' . ($report['duplicates']['affected_rows'] ?? 0));
        $this->line('JobPayment fee fixes: ' . ($report['job_payment_fee_fixes']['affected_rows'] ?? 0));
        $this->line('Contract fee fixes: ' . ($report['contract_fee_fixes']['affected_rows'] ?? 0));
        $this->line('Transaction links repaired: ' . ($report['transaction_link_repairs']['repaired_rows'] ?? 0));
        $this->line('Legacy withdrawal earnings repairs: ' . ($report['legacy_withdrawal_earnings_repairs']['repaired_rows'] ?? 0));
        $this->line('Post mismatch (job_payments): ' . ($report['post_summary']['job_payment_fee_mismatches'] ?? 0));
        $this->line('Post mismatch (contracts): ' . ($report['post_summary']['contract_fee_mismatches'] ?? 0));
        $this->line('Creators still overdrawn: ' . ($report['post_summary']['creators_overdrawn_against_settled_earnings'] ?? 0));

        return self::SUCCESS;
    }

    /**
     * Keep latest completed payment row per contract and mark older rows as failed.
     *
     * @return array{affected_rows:int, duplicate_contracts:array<int,array{contract_id:int,latest_id:int,obsolete_ids:array<int,int>}>}
     */
    private function repairDuplicateCompletedJobPayments(bool $apply): array
    {
        $completedRows = DB::table('job_payments')
            ->where('status', 'completed')
            ->orderBy('contract_id')
            ->orderBy('id')
            ->get(['id', 'contract_id'])
        ;

        $duplicateContracts = [];
        $obsoleteIds = [];

        /** @var Collection<int,\stdClass> $rows */
        foreach ($completedRows->groupBy('contract_id') as $contractId => $rows) {
            if ($rows->count() <= 1) {
                continue;
            }

            $latestId = (int) $rows->max('id');
            $obsolete = $rows
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id !== $latestId)
                ->values()
                ->all()
            ;

            if ([] === $obsolete) {
                continue;
            }

            $duplicateContracts[] = [
                'contract_id' => (int) $contractId,
                'latest_id' => $latestId,
                'obsolete_ids' => $obsolete,
            ];

            $obsoleteIds = array_merge($obsoleteIds, $obsolete);
        }

        if ($apply && [] !== $obsoleteIds) {
            DB::table('job_payments')
                ->whereIn('id', $obsoleteIds)
                ->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ])
            ;
        }

        return [
            'affected_rows' => count($obsoleteIds),
            'duplicate_contracts' => $duplicateContracts,
        ];
    }

    /**
     * Normalize platform fee + creator amount to 5% fee.
     *
     * @return array{affected_rows:int, rows:array<int,array<string,mixed>>}
     */
    private function repairJobPaymentFeePercent(bool $apply): array
    {
        $rows = DB::table('job_payments')
            ->select('id', 'contract_id', 'status', 'total_amount', 'platform_fee', 'creator_amount')
            ->whereRaw('ABS(platform_fee - ROUND(total_amount * 0.05, 2)) > 0.02 OR ABS(creator_amount - ROUND(total_amount - ROUND(total_amount * 0.05, 2), 2)) > 0.02')
            ->orderBy('id')
            ->get()
        ;

        $changes = [];

        foreach ($rows as $row) {
            $totalAmount = (float) $row->total_amount;
            $expectedFee = round($totalAmount * self::EXPECTED_FEE_PERCENT, 2);
            $expectedCreatorAmount = round($totalAmount - $expectedFee, 2);

            $changes[] = [
                'id' => (int) $row->id,
                'contract_id' => (int) $row->contract_id,
                'status' => (string) $row->status,
                'old_platform_fee' => (float) $row->platform_fee,
                'new_platform_fee' => $expectedFee,
                'old_creator_amount' => (float) $row->creator_amount,
                'new_creator_amount' => $expectedCreatorAmount,
            ];

            if ($apply) {
                DB::table('job_payments')
                    ->where('id', $row->id)
                    ->update([
                        'platform_fee' => $expectedFee,
                        'creator_amount' => $expectedCreatorAmount,
                        'updated_at' => now(),
                    ])
                ;
            }
        }

        return [
            'affected_rows' => count($changes),
            'rows' => $changes,
        ];
    }

    /**
     * Normalize contract fee fields to 5%.
     *
     * @return array{affected_rows:int, rows:array<int,array<string,mixed>>}
     */
    private function repairContractFeePercent(bool $apply): array
    {
        $rows = DB::table('contracts')
            ->select('id', 'budget', 'platform_fee', 'creator_amount', 'status', 'workflow_status')
            ->whereNotNull('budget')
            ->where('budget', '>', 0)
            ->whereRaw('ABS(COALESCE(platform_fee,0) - ROUND(budget * 0.05, 2)) > 0.02 OR ABS(COALESCE(creator_amount,0) - ROUND(budget - ROUND(budget * 0.05, 2), 2)) > 0.02')
            ->orderBy('id')
            ->get()
        ;

        $changes = [];

        foreach ($rows as $row) {
            $budget = (float) $row->budget;
            $expectedFee = round($budget * self::EXPECTED_FEE_PERCENT, 2);
            $expectedCreatorAmount = round($budget - $expectedFee, 2);

            $changes[] = [
                'id' => (int) $row->id,
                'status' => (string) $row->status,
                'workflow_status' => (string) $row->workflow_status,
                'budget' => $budget,
                'old_platform_fee' => (float) $row->platform_fee,
                'new_platform_fee' => $expectedFee,
                'old_creator_amount' => (float) $row->creator_amount,
                'new_creator_amount' => $expectedCreatorAmount,
            ];

            if ($apply) {
                DB::table('contracts')
                    ->where('id', $row->id)
                    ->update([
                        'platform_fee' => $expectedFee,
                        'creator_amount' => $expectedCreatorAmount,
                        'updated_at' => now(),
                    ])
                ;
            }
        }

        return [
            'affected_rows' => count($changes),
            'rows' => $changes,
        ];
    }

    /**
     * Try to replace legacy/non-numeric transaction_id with a valid transaction id.
     * If no matching transaction exists, creates an internal legacy settlement transaction.
     *
     * @return array{inspected_rows:int,repaired_rows:int,created_rows:int,rows:array<int,array<string,mixed>>}
     */
    private function repairLegacyTransactionLinks(bool $apply): array
    {
        $rows = DB::table('job_payments')
            ->select('id', 'contract_id', 'brand_id', 'total_amount', 'transaction_id', 'status')
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->where(function ($query): void {
                $query->whereNull('transaction_id')
                    ->orWhereRaw("transaction_id !~ '^[0-9]+$'")
                ;
            })
            ->orderBy('id')
            ->get()
        ;

        $inspected = 0;
        $repaired = 0;
        $created = 0;
        $changes = [];

        foreach ($rows as $row) {
            ++$inspected;

            $jobPaymentId = (int) $row->id;
            $contractId = (int) $row->contract_id;
            $brandId = (int) $row->brand_id;
            $amount = (float) $row->total_amount;

            $transaction = DB::table('transactions')
                ->select('id', 'amount', 'status')
                ->where('contract_id', $contractId)
                ->where('user_id', $brandId)
                ->whereIn('status', ['paid', 'succeeded'])
                ->whereRaw('ABS(amount - ?) <= 0.02', [$amount])
                ->orderByDesc('id')
                ->first()
            ;

            if (!$transaction && is_string($row->transaction_id) && preg_match('/_(\d+)$/', $row->transaction_id, $matches)) {
                $legacyKeyId = (int) ($matches[1] ?? 0);

                if ($legacyKeyId > 0) {
                    $transaction = DB::table('transactions')
                        ->select('id', 'amount', 'status')
                        ->where('user_id', $brandId)
                        ->whereIn('status', ['paid', 'succeeded'])
                        ->where('stripe_payment_intent_id', 'contract_completed_' . $legacyKeyId)
                        ->whereRaw('ABS(amount - ?) <= 0.02', [$amount])
                        ->orderByDesc('id')
                        ->first()
                    ;
                }
            }

            $action = 'linked_existing';

            if (!$transaction && $apply) {
                $legacyIntentId = sprintf('legacy_contract_settlement_%d_jp_%d', $contractId, $jobPaymentId);
                $insertedId = DB::table('transactions')->insertGetId([
                    'user_id' => $brandId,
                    'contract_id' => $contractId,
                    'stripe_payment_intent_id' => $legacyIntentId,
                    'stripe_charge_id' => null,
                    'status' => 'paid',
                    'amount' => $amount,
                    'payment_method' => 'platform_escrow_legacy',
                    'payment_data' => json_encode([
                        'type' => 'legacy_settlement_backfill',
                        'job_payment_id' => $jobPaymentId,
                        'contract_id' => $contractId,
                    ], JSON_UNESCAPED_UNICODE),
                    'metadata' => json_encode([
                        'legacy_settlement_backfill' => true,
                        'source' => 'payments:fix-payout-compliance',
                    ], JSON_UNESCAPED_UNICODE),
                    'paid_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $transaction = DB::table('transactions')
                    ->select('id', 'amount', 'status')
                    ->where('id', $insertedId)
                    ->first()
                ;

                if ($transaction) {
                    $action = 'created_legacy_transaction';
                    ++$created;
                }
            }

            if (!$transaction) {
                continue;
            }

            $changes[] = [
                'job_payment_id' => $jobPaymentId,
                'contract_id' => $contractId,
                'old_transaction_id' => $row->transaction_id,
                'new_transaction_id' => (string) $transaction->id,
                'action' => $action,
            ];

            if ($apply) {
                DB::table('job_payments')
                    ->where('id', $jobPaymentId)
                    ->update([
                        'transaction_id' => (string) $transaction->id,
                        'updated_at' => now(),
                    ])
                ;
            }

            ++$repaired;
        }

        return [
            'inspected_rows' => $inspected,
            'repaired_rows' => $repaired,
            'created_rows' => $created,
            'rows' => $changes,
        ];
    }

    /**
     * Creates auditable manual earnings adjustments for legacy creators that have
     * completed withdrawals above settled/traceable earnings.
     *
     * @return array{inspected_rows:int,repaired_rows:int,rows:array<int,array<string,mixed>>,skipped:string|null}
     */
    private function repairLegacyWithdrawalEarnings(bool $apply): array
    {
        if (!Schema::hasTable('creator_balance_adjustments')) {
            return [
                'inspected_rows' => 0,
                'repaired_rows' => 0,
                'rows' => [],
                'skipped' => 'creator_balance_adjustments_table_missing',
            ];
        }

        $rows = $this->findCreatorsOverdrawnAgainstSettledEarnings();
        $changes = [];
        $repaired = 0;

        foreach ($rows as $row) {
            $creatorId = (int) $row->creator_id;
            $overdrawnAmount = round((float) $row->overdrawn_amount, 2);

            if ($overdrawnAmount <= 0.02) {
                continue;
            }

            $change = [
                'creator_id' => $creatorId,
                'name' => (string) ($row->name ?? ''),
                'email' => (string) ($row->email ?? ''),
                'settled_earned' => round((float) $row->settled_earned, 2),
                'completed_withdrawn' => round((float) $row->completed_withdrawn, 2),
                'repair_amount' => $overdrawnAmount,
            ];

            if ($apply) {
                $adjustmentId = DB::table('creator_balance_adjustments')->insertGetId([
                    'creator_id' => $creatorId,
                    'amount' => $overdrawnAmount,
                    'kind' => 'credit',
                    'affects_available' => true,
                    'reason' => 'legacy_withdrawal_backfill',
                    'metadata' => json_encode([
                        'source' => 'payments:fix-payout-compliance',
                        'type' => 'withdrawal_without_traceable_settled_earning',
                        'auto_created' => true,
                        'created_at' => now()->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $change['adjustment_id'] = $adjustmentId;
                ++$repaired;
            }

            $changes[] = $change;
        }

        return [
            'inspected_rows' => count($rows),
            'repaired_rows' => $apply ? $repaired : count($changes),
            'rows' => $changes,
            'skipped' => null,
        ];
    }

    /**
     * @return array<int,object{creator_id:int,name:?string,email:?string,settled_earned:string,completed_withdrawn:string,overdrawn_amount:string}>
     */
    private function findCreatorsOverdrawnAgainstSettledEarnings(): array
    {
        return DB::select("
WITH valid_completed_payments AS (
    SELECT jp.creator_id, jp.creator_amount
    FROM job_payments jp
    JOIN transactions t ON t.id = CAST(jp.transaction_id AS BIGINT)
    WHERE jp.status = 'completed'
      AND jp.transaction_id ~ '^[0-9]+$'
      AND t.status IN ('paid','succeeded')
      AND (
        t.stripe_payment_intent_id LIKE 'pi_%'
        OR (
          t.payment_method = 'platform_escrow_legacy'
          AND COALESCE((t.metadata::jsonb ->> 'legacy_settlement_backfill')::boolean, false) = true
        )
        OR (
          t.payment_method = 'platform_escrow'
          AND t.stripe_payment_intent_id LIKE 'contract_completed_%'
        )
      )
),
earned_from_payments AS (
    SELECT creator_id, COALESCE(SUM(creator_amount), 0) AS amount
    FROM valid_completed_payments
    GROUP BY creator_id
),
earned_from_adjustments AS (
    SELECT
        creator_id,
        COALESCE(
            SUM(
                CASE
                    WHEN kind = 'debit' THEN -amount
                    ELSE amount
                END
            ),
            0
        ) AS amount
    FROM creator_balance_adjustments
    WHERE is_active = true
    GROUP BY creator_id
),
withdrawn AS (
    SELECT creator_id, COALESCE(SUM(amount), 0) AS completed_withdrawn
    FROM withdrawals
    WHERE status = 'completed'
    GROUP BY creator_id
)
SELECT
    u.id AS creator_id,
    u.name,
    u.email,
    COALESCE(ep.amount, 0) + COALESCE(ea.amount, 0) AS settled_earned,
    COALESCE(w.completed_withdrawn, 0) AS completed_withdrawn,
    ROUND(COALESCE(w.completed_withdrawn, 0) - (COALESCE(ep.amount, 0) + COALESCE(ea.amount, 0)), 2) AS overdrawn_amount
FROM users u
LEFT JOIN earned_from_payments ep ON ep.creator_id = u.id
LEFT JOIN earned_from_adjustments ea ON ea.creator_id = u.id
LEFT JOIN withdrawn w ON w.creator_id = u.id
WHERE COALESCE(w.completed_withdrawn, 0) > (COALESCE(ep.amount, 0) + COALESCE(ea.amount, 0))
ORDER BY overdrawn_amount DESC, u.id ASC
");
    }

    /**
     * @return array{inspected_rows:int,changed_rows:int,sample:array<int,array<string,mixed>>}
     */
    private function recalculateCreatorBalances(bool $apply): array
    {
        $creatorIds = DB::table('creator_balances')
            ->pluck('creator_id')
            ->merge(DB::table('job_payments')->pluck('creator_id'))
            ->merge(DB::table('withdrawals')->pluck('creator_id'))
            ->filter(fn ($id) => null !== $id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
        ;

        $inspected = 0;
        $changed = 0;
        $sample = [];

        foreach ($creatorIds as $creatorId) {
            ++$inspected;

            /** @var CreatorBalance $balance */
            $balance = CreatorBalance::firstOrCreate(
                ['creator_id' => $creatorId],
                [
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'total_earned' => 0,
                    'total_withdrawn' => 0,
                ]
            );

            $before = [
                'available_balance' => (float) $balance->available_balance,
                'pending_balance' => (float) $balance->pending_balance,
                'total_earned' => (float) $balance->total_earned,
                'total_withdrawn' => (float) $balance->total_withdrawn,
            ];

            if ($apply) {
                $balance->recalculateFromPayments();
                $balance->refresh();
            }

            $after = $apply ? [
                'available_balance' => (float) $balance->available_balance,
                'pending_balance' => (float) $balance->pending_balance,
                'total_earned' => (float) $balance->total_earned,
                'total_withdrawn' => (float) $balance->total_withdrawn,
            ] : $before;

            if (
                abs($before['available_balance'] - $after['available_balance']) > 0.01
                || abs($before['pending_balance'] - $after['pending_balance']) > 0.01
                || abs($before['total_earned'] - $after['total_earned']) > 0.01
                || abs($before['total_withdrawn'] - $after['total_withdrawn']) > 0.01
            ) {
                ++$changed;

                if (count($sample) < 100) {
                    $sample[] = [
                        'creator_id' => $creatorId,
                        'before' => $before,
                        'after' => $after,
                    ];
                }
            }
        }

        return [
            'inspected_rows' => $inspected,
            'changed_rows' => $changed,
            'sample' => $sample,
        ];
    }

    /**
     * @return array{
     *   job_payment_fee_mismatches:int,
     *   contract_fee_mismatches:int,
     *   duplicate_completed_contracts:int,
     *   job_payments_without_numeric_transaction_id:int,
     *   creators_overdrawn_against_settled_earnings:int
     * }
     */
    private function buildPostSummary(): array
    {
        $jobPaymentFeeMismatches = (int) DB::table('job_payments')
            ->whereRaw('ABS(platform_fee - ROUND(total_amount * 0.05, 2)) > 0.02 OR ABS(creator_amount - ROUND(total_amount - ROUND(total_amount * 0.05, 2), 2)) > 0.02')
            ->count()
        ;

        $contractFeeMismatches = (int) DB::table('contracts')
            ->whereNotNull('budget')
            ->where('budget', '>', 0)
            ->whereRaw('ABS(COALESCE(platform_fee,0) - ROUND(budget * 0.05, 2)) > 0.02 OR ABS(COALESCE(creator_amount,0) - ROUND(budget - ROUND(budget * 0.05, 2), 2)) > 0.02')
            ->count()
        ;

        $duplicateCompletedContracts = (int) DB::table('job_payments')
            ->selectRaw('contract_id, COUNT(*) as total_rows')
            ->where('status', 'completed')
            ->groupBy('contract_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count()
        ;

        $jobPaymentsWithoutNumericTransactionId = (int) DB::table('job_payments')
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->where(function ($query): void {
                $query->whereNull('transaction_id')
                    ->orWhereRaw("transaction_id !~ '^[0-9]+$'")
                ;
            })
            ->count()
        ;

        $creatorsOverdrawn = 0;
        if (Schema::hasTable('creator_balance_adjustments')) {
            $creatorsOverdrawn = count($this->findCreatorsOverdrawnAgainstSettledEarnings());
        }

        return [
            'job_payment_fee_mismatches' => $jobPaymentFeeMismatches,
            'contract_fee_mismatches' => $contractFeeMismatches,
            'duplicate_completed_contracts' => $duplicateCompletedContracts,
            'job_payments_without_numeric_transaction_id' => $jobPaymentsWithoutNumericTransactionId,
            'creators_overdrawn_against_settled_earnings' => $creatorsOverdrawn,
        ];
    }

    private function persistReport(array $report): string
    {
        $option = $this->option('output');
        $targetPath = is_string($option) && '' !== trim($option)
            ? trim($option)
            : storage_path('app/payout_compliance_fix_' . now()->format('Ymd_His') . '.json')
        ;

        if (!str_starts_with($targetPath, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $targetPath)) {
            $targetPath = storage_path($targetPath);
        }

        $directory = dirname($targetPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($targetPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $targetPath;
    }
}

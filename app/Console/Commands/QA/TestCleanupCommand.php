<?php

declare(strict_types=1);

namespace App\Console\Commands\QA;

use App\Models\Campaign\Campaign;
use App\Models\Payment\CreatorBalance;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Transaction;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;

class TestCleanupCommand extends Command
{
    protected $signature = 'qa:test-cleanup
        {--dry-run : Only generate inventory and reports}
        {--apply : Apply refunds and deletions}
        {--force : Required together with --apply}
        {--campaign-ids=135,137,139 : Comma-separated campaign IDs}
        {--output-dir= : Optional custom output folder under storage/app}';

    protected $description = 'Cleanup test campaigns with auditable dry-run/apply flow and Stripe refund support';

    private const array REFUNDABLE_TRANSACTION_STATUSES = ['paid', 'succeeded'];

    public function handle(): int
    {
        $campaignIds = $this->parseCampaignIds();
        if (empty($campaignIds)) {
            $this->error('No valid campaign IDs provided.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || !$apply;

        if ($apply && !$this->option('force')) {
            $this->error('Refusing to apply changes without --force.');

            return self::FAILURE;
        }

        $reportDir = $this->resolveReportDirectory();

        $this->info('Building cleanup inventory...');
        $inventory = $this->buildInventory($campaignIds);
        $beforeSnapshot = $this->captureCreatorBalances($inventory['creator_ids']);

        $dryRunPayload = [
            'mode' => 'dry-run',
            'generated_at_utc' => Carbon::now('UTC')->toDateTimeString(),
            'campaign_ids' => $campaignIds,
            'inventory' => $inventory,
            'balances_before' => $beforeSnapshot,
        ];

        $dryRunJsonPath = $this->writeJsonReport($reportDir, 'dry-run', $dryRunPayload);
        $dryRunMarkdownPath = $this->writeMarkdownReport($reportDir, 'dry-run', $dryRunPayload);

        $this->line("Dry-run JSON: {$dryRunJsonPath}");
        $this->line("Dry-run MD: {$dryRunMarkdownPath}");
        $this->outputInventorySummary($inventory);

        if (!$apply) {
            $this->info('Dry-run completed. No data changed.');

            return self::SUCCESS;
        }

        $this->warn('Applying cleanup...');

        $applyResult = [
            'refunded' => [],
            'refund_failures' => [],
            'deleted_campaign_ids' => [],
            'delete_failures' => [],
            'balance_recalculation' => [],
        ];

        $this->configureStripeKey();

        foreach ($inventory['transactions'] as $transactionData) {
            $transactionId = (int) ($transactionData['id'] ?? 0);
            if ($transactionId <= 0) {
                continue;
            }

            $transaction = Transaction::find($transactionId);
            if (!$transaction) {
                $applyResult['refund_failures'][] = [
                    'transaction_id' => $transactionId,
                    'error' => 'transaction_not_found',
                ];
                continue;
            }

            $paymentIntentId = (string) ($transaction->stripe_payment_intent_id ?? '');
            if ('' === $paymentIntentId || !str_starts_with($paymentIntentId, 'pi_')) {
                $applyResult['refunded'][] = [
                    'transaction_id' => $transactionId,
                    'status' => 'skipped_no_payment_intent',
                ];
                continue;
            }

            if ('refunded' === (string) $transaction->status) {
                $applyResult['refunded'][] = [
                    'transaction_id' => $transactionId,
                    'status' => 'already_refunded',
                    'payment_intent_id' => $paymentIntentId,
                ];
                continue;
            }

            if (!in_array((string) $transaction->status, self::REFUNDABLE_TRANSACTION_STATUSES, true)) {
                $applyResult['refund_failures'][] = [
                    'transaction_id' => $transactionId,
                    'payment_intent_id' => $paymentIntentId,
                    'error' => 'transaction_not_refundable_status',
                    'status' => (string) $transaction->status,
                ];
                continue;
            }

            try {
                $refund = Refund::create([
                    'payment_intent' => $paymentIntentId,
                    'reason' => 'requested_by_customer',
                    'metadata' => [
                        'source' => 'qa:test-cleanup',
                        'transaction_id' => (string) $transactionId,
                        'campaign_ids' => implode(',', $campaignIds),
                    ],
                ]);

                $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
                $metadata['qa_cleanup'] = array_merge(
                    is_array($metadata['qa_cleanup'] ?? null) ? $metadata['qa_cleanup'] : [],
                    [
                        'refunded_at_utc' => Carbon::now('UTC')->toDateTimeString(),
                        'refund_id' => (string) $refund->id,
                        'payment_intent_id' => $paymentIntentId,
                    ]
                );

                $transaction->update([
                    'status' => 'refunded',
                    'metadata' => $metadata,
                ]);

                $applyResult['refunded'][] = [
                    'transaction_id' => $transactionId,
                    'payment_intent_id' => $paymentIntentId,
                    'refund_id' => (string) $refund->id,
                ];
            } catch (Exception $e) {
                Log::error('qa:test-cleanup refund failed', [
                    'transaction_id' => $transactionId,
                    'payment_intent_id' => $paymentIntentId,
                    'error' => $e->getMessage(),
                ]);

                $applyResult['refund_failures'][] = [
                    'transaction_id' => $transactionId,
                    'payment_intent_id' => $paymentIntentId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        foreach ($campaignIds as $campaignId) {
            $campaign = Campaign::find($campaignId);
            if (!$campaign) {
                $applyResult['deleted_campaign_ids'][] = [
                    'campaign_id' => $campaignId,
                    'status' => 'already_deleted',
                ];
                continue;
            }

            try {
                $campaign->delete();
                $applyResult['deleted_campaign_ids'][] = [
                    'campaign_id' => $campaignId,
                    'status' => 'deleted',
                ];
            } catch (Exception $e) {
                Log::error('qa:test-cleanup campaign delete failed', [
                    'campaign_id' => $campaignId,
                    'error' => $e->getMessage(),
                ]);

                $applyResult['delete_failures'][] = [
                    'campaign_id' => $campaignId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        foreach ($inventory['creator_ids'] as $creatorId) {
            $balance = CreatorBalance::where('creator_id', $creatorId)->first();
            if (!$balance) {
                $applyResult['balance_recalculation'][] = [
                    'creator_id' => $creatorId,
                    'status' => 'no_balance_record',
                ];
                continue;
            }

            $before = [
                'available_balance' => (float) $balance->available_balance,
                'pending_balance' => (float) $balance->pending_balance,
                'total_earned' => (float) $balance->total_earned,
                'total_withdrawn' => (float) $balance->total_withdrawn,
            ];

            $balance->recalculateFromPayments();
            $balance->refresh();

            $after = [
                'available_balance' => (float) $balance->available_balance,
                'pending_balance' => (float) $balance->pending_balance,
                'total_earned' => (float) $balance->total_earned,
                'total_withdrawn' => (float) $balance->total_withdrawn,
            ];

            $applyResult['balance_recalculation'][] = [
                'creator_id' => $creatorId,
                'status' => 'recalculated',
                'before' => $before,
                'after' => $after,
            ];
        }

        $afterInventory = $this->buildInventory($campaignIds);
        $afterSnapshot = $this->captureCreatorBalances($inventory['creator_ids']);

        $applyPayload = [
            'mode' => 'apply',
            'generated_at_utc' => Carbon::now('UTC')->toDateTimeString(),
            'campaign_ids' => $campaignIds,
            'results' => $applyResult,
            'inventory_before' => $inventory,
            'inventory_after' => $afterInventory,
            'balances_before' => $beforeSnapshot,
            'balances_after' => $afterSnapshot,
        ];

        $applyJsonPath = $this->writeJsonReport($reportDir, 'apply', $applyPayload);
        $applyMarkdownPath = $this->writeMarkdownReport($reportDir, 'apply', $applyPayload);

        $this->line("Apply JSON: {$applyJsonPath}");
        $this->line("Apply MD: {$applyMarkdownPath}");

        if (!empty($applyResult['refund_failures']) || !empty($applyResult['delete_failures'])) {
            $this->warn('Cleanup applied with failures. Review report files.');

            return self::FAILURE;
        }

        $this->info('Cleanup applied successfully.');

        return self::SUCCESS;
    }

    /**
     * @return int[]
     */
    private function parseCampaignIds(): array
    {
        $raw = (string) $this->option('campaign-ids');
        $parts = collect(explode(',', $raw))
            ->map(fn(string $part) => trim($part))
            ->filter(fn(string $part) => '' !== $part && is_numeric($part))
            ->map(fn(string $part) => (int) $part)
            ->unique()
            ->values()
            ->all()
        ;

        sort($parts);

        return $parts;
    }

    /**
     * @return array{
     *   campaigns:array<int,array<string,mixed>>,
     *   chat_rooms:array<int,array<string,mixed>>,
     *   offers:array<int,array<string,mixed>>,
     *   contracts:array<int,array<string,mixed>>,
     *   job_payments:array<int,array<string,mixed>>,
     *   transactions:array<int,array<string,mixed>>,
     *   creator_balances:array<int,array<string,mixed>>,
     *   stripe_payment_intents:array<int,array<string,mixed>>,
     *   creator_ids:array<int,int>,
     *   summary:array<string,int>
     * }
     */
    private function buildInventory(array $campaignIds): array
    {
        $campaigns = Campaign::query()
            ->whereIn('id', $campaignIds)
            ->get()
            ->map(fn(Campaign $campaign) => [
                'id' => $campaign->id,
                'brand_id' => $campaign->brand_id,
                'title' => $campaign->title,
                'status' => $campaign->status,
                'budget' => (float) $campaign->budget,
                'created_at' => $campaign->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all()
        ;

        $chatRooms = \DB::table('chat_rooms')
            ->whereIn('campaign_id', $campaignIds)
            ->select('id', 'campaign_id', 'room_id', 'chat_status', 'is_active', 'created_at')
            ->orderBy('id')
            ->get()
            ->map(fn($row) => (array) $row)
            ->all()
        ;

        $chatRoomIds = collect($chatRooms)->pluck('id')->map(fn($id) => (int) $id)->all();

        $offers = \DB::table('offers')
            ->where(function ($query) use ($campaignIds, $chatRoomIds): void {
                $query->whereIn('campaign_id', $campaignIds);
                if (!empty($chatRoomIds)) {
                    $query->orWhereIn('chat_room_id', $chatRoomIds);
                }
            })
            ->select('id', 'campaign_id', 'chat_room_id', 'brand_id', 'creator_id', 'status', 'budget', 'created_at')
            ->orderBy('id')
            ->get()
            ->map(fn($row) => (array) $row)
            ->all()
        ;

        $offerIds = collect($offers)->pluck('id')->map(fn($id) => (int) $id)->all();

        $contracts = [];
        if (!empty($offerIds)) {
            $contracts = \DB::table('contracts')
                ->whereIn('offer_id', $offerIds)
                ->select('id', 'offer_id', 'brand_id', 'creator_id', 'status', 'workflow_status', 'budget', 'creator_amount', 'created_at')
                ->orderBy('id')
                ->get()
                ->map(fn($row) => (array) $row)
                ->all()
            ;
        }

        $contractIds = collect($contracts)->pluck('id')->map(fn($id) => (int) $id)->all();

        $jobPayments = [];
        if (!empty($contractIds)) {
            $jobPayments = JobPayment::query()
                ->whereIn('contract_id', $contractIds)
                ->get(['id', 'contract_id', 'brand_id', 'creator_id', 'status', 'total_amount', 'platform_fee', 'creator_amount', 'transaction_id', 'paid_at', 'processed_at'])
                ->map(fn(JobPayment $payment) => [
                    'id' => $payment->id,
                    'contract_id' => $payment->contract_id,
                    'brand_id' => $payment->brand_id,
                    'creator_id' => $payment->creator_id,
                    'status' => $payment->status,
                    'total_amount' => (float) $payment->total_amount,
                    'platform_fee' => (float) $payment->platform_fee,
                    'creator_amount' => (float) $payment->creator_amount,
                    'transaction_id' => $payment->transaction_id,
                    'paid_at' => $payment->paid_at?->toDateTimeString(),
                    'processed_at' => $payment->processed_at?->toDateTimeString(),
                ])
                ->values()
                ->all()
            ;
        }

        $transactionIds = collect($jobPayments)
            ->pluck('transaction_id')
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->merge(
                collect($contractIds)->isEmpty()
                    ? collect()
                    : Transaction::query()->whereIn('contract_id', $contractIds)->pluck('id')
            )
            ->unique()
            ->values()
            ->all()
        ;

        $transactions = [];
        if (!empty($transactionIds)) {
            $transactions = Transaction::query()
                ->whereIn('id', $transactionIds)
                ->get(['id', 'user_id', 'contract_id', 'status', 'amount', 'payment_method', 'stripe_payment_intent_id', 'paid_at', 'metadata'])
                ->map(fn(Transaction $transaction) => [
                    'id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'contract_id' => $transaction->contract_id,
                    'status' => $transaction->status,
                    'amount' => (float) $transaction->amount,
                    'payment_method' => $transaction->payment_method,
                    'stripe_payment_intent_id' => $transaction->stripe_payment_intent_id,
                    'paid_at' => $transaction->paid_at?->toDateTimeString(),
                    'metadata' => $transaction->metadata,
                ])
                ->values()
                ->all()
            ;
        }

        $creatorIds = collect($contracts)
            ->pluck('creator_id')
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all()
        ;

        $creatorBalances = [];
        if (!empty($creatorIds)) {
            $creatorBalances = CreatorBalance::query()
                ->whereIn('creator_id', $creatorIds)
                ->get(['id', 'creator_id', 'available_balance', 'pending_balance', 'total_earned', 'total_withdrawn', 'updated_at'])
                ->map(fn(CreatorBalance $balance) => [
                    'id' => $balance->id,
                    'creator_id' => $balance->creator_id,
                    'available_balance' => (float) $balance->available_balance,
                    'pending_balance' => (float) $balance->pending_balance,
                    'total_earned' => (float) $balance->total_earned,
                    'total_withdrawn' => (float) $balance->total_withdrawn,
                    'updated_at' => $balance->updated_at?->toDateTimeString(),
                ])
                ->values()
                ->all()
            ;
        }

        $stripePaymentIntents = $this->collectPaymentIntentDiagnostics($transactions);

        return [
            'campaigns' => $campaigns,
            'chat_rooms' => $chatRooms,
            'offers' => $offers,
            'contracts' => $contracts,
            'job_payments' => $jobPayments,
            'transactions' => $transactions,
            'creator_balances' => $creatorBalances,
            'stripe_payment_intents' => $stripePaymentIntents,
            'creator_ids' => $creatorIds,
            'summary' => [
                'campaigns' => count($campaigns),
                'chat_rooms' => count($chatRooms),
                'offers' => count($offers),
                'contracts' => count($contracts),
                'job_payments' => count($jobPayments),
                'transactions' => count($transactions),
                'creator_balances' => count($creatorBalances),
                'stripe_payment_intents' => count($stripePaymentIntents),
            ],
        ];
    }

    /**
     * @param int[] $creatorIds
     * @return array<int,array<string,mixed>>
     */
    private function captureCreatorBalances(array $creatorIds): array
    {
        if (empty($creatorIds)) {
            return [];
        }

        return CreatorBalance::query()
            ->whereIn('creator_id', $creatorIds)
            ->get(['creator_id', 'available_balance', 'pending_balance', 'total_earned', 'total_withdrawn', 'updated_at'])
            ->map(fn(CreatorBalance $balance) => [
                'creator_id' => $balance->creator_id,
                'available_balance' => (float) $balance->available_balance,
                'pending_balance' => (float) $balance->pending_balance,
                'total_earned' => (float) $balance->total_earned,
                'total_withdrawn' => (float) $balance->total_withdrawn,
                'updated_at' => $balance->updated_at?->toDateTimeString(),
            ])
            ->values()
            ->all()
        ;
    }

    /**
     * @param array<int,array<string,mixed>> $transactions
     * @return array<int,array<string,mixed>>
     */
    private function collectPaymentIntentDiagnostics(array $transactions): array
    {
        $this->configureStripeKey();

        $paymentIntentIds = collect($transactions)
            ->pluck('stripe_payment_intent_id')
            ->filter(fn($id) => is_string($id) && str_starts_with($id, 'pi_'))
            ->unique()
            ->values()
            ->all()
        ;

        $diagnostics = [];
        foreach ($paymentIntentIds as $paymentIntentId) {
            try {
                $intent = PaymentIntent::retrieve($paymentIntentId, [
                    'expand' => ['latest_charge.balance_transaction'],
                ]);

                $latestCharge = $intent->latest_charge;
                $balanceTx = is_object($latestCharge) ? ($latestCharge->balance_transaction ?? null) : null;

                $diagnostics[] = [
                    'payment_intent_id' => $intent->id,
                    'status' => $intent->status,
                    'amount_cents' => (int) ($intent->amount ?? 0),
                    'currency' => $intent->currency,
                    'created_utc' => Carbon::createFromTimestampUTC((int) $intent->created)->toDateTimeString(),
                    'latest_charge_id' => is_object($latestCharge) ? ($latestCharge->id ?? null) : (is_string($latestCharge) ? $latestCharge : null),
                    'balance_transaction_status' => is_object($balanceTx) ? ($balanceTx->status ?? null) : null,
                    'available_on_utc' => is_object($balanceTx) && isset($balanceTx->available_on)
                        ? Carbon::createFromTimestampUTC((int) $balanceTx->available_on)->toDateTimeString()
                        : null,
                    'livemode' => (bool) ($intent->livemode ?? false),
                ];
            } catch (Exception $e) {
                $diagnostics[] = [
                    'payment_intent_id' => $paymentIntentId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $diagnostics;
    }

    private function configureStripeKey(): void
    {
        $secret = config('services.stripe.secret');
        if (empty($secret)) {
            return;
        }

        Stripe::setApiKey((string) $secret);
    }

    private function resolveReportDirectory(): string
    {
        $custom = trim((string) $this->option('output-dir'));
        if ('' !== $custom) {
            $dir = storage_path('app/' . trim($custom, '/\\'));
            File::ensureDirectoryExists($dir);

            return $dir;
        }

        $dir = storage_path('app/qa-reports/' . Carbon::now('UTC')->format('Ymd_His'));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeJsonReport(string $reportDir, string $phase, array $payload): string
    {
        $path = $reportDir . DIRECTORY_SEPARATOR . $phase . '.json';
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeMarkdownReport(string $reportDir, string $phase, array $payload): string
    {
        $summary = $payload['inventory']['summary'] ?? $payload['inventory_before']['summary'] ?? [];
        $campaignIds = $payload['campaign_ids'] ?? [];

        $lines = [
            '# QA Test Cleanup Report',
            '',
            '- Phase: ' . $phase,
            '- Generated (UTC): ' . (string) ($payload['generated_at_utc'] ?? Carbon::now('UTC')->toDateTimeString()),
            '- Campaign IDs: ' . implode(', ', $campaignIds),
            '',
            '## Inventory Summary',
            '- Campaigns: ' . (string) ($summary['campaigns'] ?? 0),
            '- Chat rooms: ' . (string) ($summary['chat_rooms'] ?? 0),
            '- Offers: ' . (string) ($summary['offers'] ?? 0),
            '- Contracts: ' . (string) ($summary['contracts'] ?? 0),
            '- Job payments: ' . (string) ($summary['job_payments'] ?? 0),
            '- Transactions: ' . (string) ($summary['transactions'] ?? 0),
            '- Creator balances: ' . (string) ($summary['creator_balances'] ?? 0),
            '- Stripe payment intents: ' . (string) ($summary['stripe_payment_intents'] ?? 0),
        ];

        if ('apply' === $phase && isset($payload['results'])) {
            $results = $payload['results'];
            $lines[] = '';
            $lines[] = '## Apply Results';
            $lines[] = '- Refunded: ' . (string) count($results['refunded'] ?? []);
            $lines[] = '- Refund failures: ' . (string) count($results['refund_failures'] ?? []);
            $lines[] = '- Deleted campaigns: ' . (string) count($results['deleted_campaign_ids'] ?? []);
            $lines[] = '- Delete failures: ' . (string) count($results['delete_failures'] ?? []);
            $lines[] = '- Balance recalculations: ' . (string) count($results['balance_recalculation'] ?? []);
        }

        $path = $reportDir . DIRECTORY_SEPARATOR . $phase . '.md';
        File::put($path, implode(PHP_EOL, $lines));

        return $path;
    }

    /**
     * @param array<string,mixed> $inventory
     */
    private function outputInventorySummary(array $inventory): void
    {
        $summary = $inventory['summary'] ?? [];

        $this->table(
            ['entity', 'count'],
            [
                ['campaigns', (string) ($summary['campaigns'] ?? 0)],
                ['chat_rooms', (string) ($summary['chat_rooms'] ?? 0)],
                ['offers', (string) ($summary['offers'] ?? 0)],
                ['contracts', (string) ($summary['contracts'] ?? 0)],
                ['job_payments', (string) ($summary['job_payments'] ?? 0)],
                ['transactions', (string) ($summary['transactions'] ?? 0)],
                ['creator_balances', (string) ($summary['creator_balances'] ?? 0)],
                ['stripe_payment_intents', (string) ($summary['stripe_payment_intents'] ?? 0)],
            ]
        );
    }
}

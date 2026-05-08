<?php

declare (strict_types = 1);

namespace App\Console\Commands\Subscription;

use App\Models\Payment\Subscription;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class EnforcePremiumConformity extends Command
{
    protected $signature = 'subscriptions:enforce-premium-conformity {--dry-run : Analyze only, do not persist changes}';

    protected $description = 'Enforce premium data conformity between users and subscriptions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now    = now();

        $report = [
            'generated_at' => $now->toDateTimeString(),
            'dry_run'      => $dryRun,
            'summary'      => [
                'expired_invalid_active_subscriptions'         => 0,
                'promoted_users_from_active_subscription'      => 0,
                'synced_user_premium_expiry_from_subscription' => 0,
                'demoted_users_without_active_subscription'    => 0,
            ],
            'changes'      => [
                'subscriptions_expired' => [],
                'users_promoted'        => [],
                'users_synced'          => [],
                'users_demoted'         => [],
            ],
        ];

        $run = function () use (&$report, $dryRun, $now): void {
            $invalidActiveSubscriptions = Subscription::query()
                ->where('status', Subscription::STATUS_ACTIVE)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '<=', $now)
                    ;
                })
                ->get()
            ;

            foreach ($invalidActiveSubscriptions as $subscription) {
                $oldStripeStatus = (string) ($subscription->stripe_status ?? '');
                $newStripeStatus = $oldStripeStatus;
                if ('' === $oldStripeStatus || in_array(strtolower($oldStripeStatus), ['active', 'trialing', 'past_due', 'unpaid'], true)) {
                    $newStripeStatus = Subscription::STATUS_EXPIRED;
                }

                if (! $dryRun) {
                    $subscription->forceFill([
                        'status'        => Subscription::STATUS_EXPIRED,
                        'stripe_status' => $newStripeStatus,
                    ])->save();
                }

                $report['summary']['expired_invalid_active_subscriptions']++;
                $report['changes']['subscriptions_expired'][] = [
                    'id'                => (int) $subscription->id,
                    'user_id'           => (int) $subscription->user_id,
                    'old_status'        => Subscription::STATUS_ACTIVE,
                    'new_status'        => Subscription::STATUS_EXPIRED,
                    'expires_at'        => $this->formatDate($subscription->expires_at),
                    'old_stripe_status' => $oldStripeStatus,
                    'new_stripe_status' => $newStripeStatus,
                ];
            }

            $usersToPromote = User::query()
                ->where('role', 'creator')
                ->where(function ($query): void {
                    $query->where('has_premium', false)
                        ->orWhereNull('has_premium')
                    ;
                })
                ->whereHas('subscriptions', function ($query) use ($now): void {
                    $query->where('status', Subscription::STATUS_ACTIVE)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>', $now)
                    ;
                })
                ->with(['subscriptions' => function ($query) use ($now): void {
                    $query->select(['id', 'user_id', 'status', 'expires_at'])
                        ->where('status', Subscription::STATUS_ACTIVE)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>', $now)
                        ->orderByDesc('expires_at')
                    ;
                }])
                ->get()
            ;

            foreach ($usersToPromote as $user) {
                $latestExpiry = $this->getLatestExpiry($user->subscriptions);
                if (! $latestExpiry) {
                    continue;
                }

                if (! $dryRun) {
                    $user->forceFill([
                        'has_premium'        => true,
                        'premium_expires_at' => $latestExpiry,
                    ])->save();
                }

                $report['summary']['promoted_users_from_active_subscription']++;
                $report['changes']['users_promoted'][] = [
                    'id'                 => (int) $user->id,
                    'email'              => (string) $user->email,
                    'premium_expires_at' => $latestExpiry->toDateTimeString(),
                ];
            }

            $usersToSyncExpiry = User::query()
                ->where('role', 'creator')
                ->where('has_premium', true)
                ->whereHas('subscriptions', function ($query) use ($now): void {
                    $query->where('status', Subscription::STATUS_ACTIVE)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>', $now)
                    ;
                })
                ->with(['subscriptions' => function ($query) use ($now): void {
                    $query->select(['id', 'user_id', 'status', 'expires_at'])
                        ->where('status', Subscription::STATUS_ACTIVE)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>', $now)
                        ->orderByDesc('expires_at')
                    ;
                }])
                ->get()
            ;

            foreach ($usersToSyncExpiry as $user) {
                $latestExpiry = $this->getLatestExpiry($user->subscriptions);
                if (! $latestExpiry) {
                    continue;
                }

                $storedExpiry = $this->toCarbon($user->premium_expires_at);
                if ($storedExpiry && $storedExpiry->equalTo($latestExpiry)) {
                    continue;
                }

                if (! $dryRun) {
                    $user->forceFill([
                        'has_premium'        => true,
                        'premium_expires_at' => $latestExpiry,
                    ])->save();
                }

                $report['summary']['synced_user_premium_expiry_from_subscription']++;
                $report['changes']['users_synced'][] = [
                    'id'                     => (int) $user->id,
                    'email'                  => (string) $user->email,
                    'old_premium_expires_at' => $this->formatDate($storedExpiry),
                    'new_premium_expires_at' => $latestExpiry->toDateTimeString(),
                ];
            }

            $usersToDemote = User::query()
                ->where('role', 'creator')
                ->where('has_premium', true)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('premium_expires_at')
                        ->orWhere('premium_expires_at', '<=', $now)
                    ;
                })
                ->whereDoesntHave('subscriptions', function ($query) use ($now): void {
                    $query->where('status', Subscription::STATUS_ACTIVE)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>', $now)
                    ;
                })
                ->get()
            ;

            foreach ($usersToDemote as $user) {
                if (! $dryRun) {
                    $user->forceFill(['has_premium' => false])->save();
                }

                $report['summary']['demoted_users_without_active_subscription']++;
                $report['changes']['users_demoted'][] = [
                    'id'                 => (int) $user->id,
                    'email'              => (string) $user->email,
                    'premium_expires_at' => $this->formatDate($user->premium_expires_at),
                ];
            }
        };

        try {
            if ($dryRun) {
                $run();
            } else {
                DB::transaction($run);
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to enforce premium conformity', [
                'error' => $exception->getMessage(),
            ]);

            $this->error('Erro ao aplicar conformidade: ' . $exception->getMessage());

            return 1;
        }

        $reportDir = storage_path('app/premium_conformity_reports');
        File::ensureDirectoryExists($reportDir);
        $timestamp     = now()->format('Ymd_His');
        $versionedPath = $reportDir . "/premium_conformity_{$timestamp}.json";
        $latestPath    = $reportDir . '/premium_conformity_latest.json';
        $reportJson    = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (false !== $reportJson) {
            File::put($versionedPath, $reportJson);
            File::put($latestPath, $reportJson);
        }

        $this->info('Premium conformity check finalizada.');
        $this->line('Dry-run: ' . ($dryRun ? 'sim' : 'não'));
        $this->line('Subscriptions expiradas: ' . $report['summary']['expired_invalid_active_subscriptions']);
        $this->line('Usuarios promovidos: ' . $report['summary']['promoted_users_from_active_subscription']);
        $this->line('Usuarios sincronizados: ' . $report['summary']['synced_user_premium_expiry_from_subscription']);
        $this->line('Usuarios rebaixados: ' . $report['summary']['demoted_users_without_active_subscription']);
        $this->line('Relatorio: ' . $versionedPath);

        return 0;
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getLatestExpiry($subscriptions): ?Carbon
    {
        $latest = null;

        foreach ($subscriptions as $subscription) {
            $expiresAt = $this->toCarbon($subscription->expires_at);
            if (! $expiresAt) {
                continue;
            }

            if (! $latest || $expiresAt->gt($latest)) {
                $latest = $expiresAt;
            }
        }

        return $latest;
    }

    private function formatDate(mixed $value): ?string
    {
        $parsed = $this->toCarbon($value);

        return $parsed?->toDateTimeString();
    }
}

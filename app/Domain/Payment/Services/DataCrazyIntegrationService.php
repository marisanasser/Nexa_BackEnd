<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\Payment\DataCrazyIntegrationEvent;
use App\Models\Payment\SubscriptionPlan;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Throwable;

class DataCrazyIntegrationService
{
    public function __construct(
        private StripeWrapper $stripeWrapper,
        private StripeCustomerService $customerService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function createCheckout(array $payload): array
    {
        $externalEventId = (string) $payload['external_event_id'];
        $event = DataCrazyIntegrationEvent::firstOrCreate(
            ['external_event_id' => $externalEventId],
            [
                'event_type' => (string) ($payload['event_type'] ?? 'checkout_requested'),
                'external_id' => $this->nullableString($payload['external_id'] ?? null),
                'business_id' => $this->nullableString($payload['business_id'] ?? null),
                'plan_code' => (string) $payload['plan_code'],
                'status' => DataCrazyIntegrationEvent::STATUS_RECEIVED,
                'request_payload' => $payload,
            ]
        );

        if (!$event->wasRecentlyCreated) {
            $idempotentResponse = $this->buildIdempotentResponse($event);
            if (null !== $idempotentResponse) {
                return $idempotentResponse;
            }
        }

        $event->forceFill([
            'event_type' => (string) ($payload['event_type'] ?? 'checkout_requested'),
            'external_id' => $this->nullableString($payload['external_id'] ?? null),
            'business_id' => $this->nullableString($payload['business_id'] ?? null),
            'plan_code' => (string) $payload['plan_code'],
            'status' => DataCrazyIntegrationEvent::STATUS_PROCESSING,
            'error_message' => null,
            'request_payload' => $payload,
        ])->save();

        try {
            $this->assertRedirectAllowed($this->nullableString($payload['redirect_url'] ?? null));

            $plan = $this->resolvePlan((string) $payload['plan_code']);
            $user = $this->findOrCreateUser($payload);
            $customerId = $this->customerService->ensureStripeCustomer($user);
            $trialDays = $this->resolveTrialDays($payload);
            $session = $this->createStripeCheckoutSession($customerId, $user, $plan, $event, $payload, $trialDays);

            $body = [
                'success' => true,
                'checkout_url' => $session->url,
                'checkout_session_id' => $session->id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'trial_days' => $trialDays,
                'external_event_id' => $event->external_event_id,
                'event_id' => $event->id,
                'idempotent' => false,
            ];

            $event->forceFill([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'stripe_checkout_session_id' => $session->id,
                'status' => DataCrazyIntegrationEvent::STATUS_PROCESSED,
                'response_payload' => $body,
                'processed_at' => now(),
            ])->save();

            Log::info('DataCrazy checkout created', [
                'event_id' => $event->id,
                'external_event_id' => $event->external_event_id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'checkout_session_id' => $session->id,
            ]);

            return ['status' => 200, 'body' => $body];
        } catch (Throwable $e) {
            $event->forceFill([
                'status' => DataCrazyIntegrationEvent::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ])->save();

            Log::error('DataCrazy checkout creation failed', [
                'event_id' => $event->id,
                'external_event_id' => $event->external_event_id,
                'error' => $e->getMessage(),
            ]);

            $status = $e instanceof Exception && in_array($e->getCode(), [404, 409, 422], true)
                ? $e->getCode()
                : 500;

            return [
                'status' => $status,
                'body' => [
                    'success' => false,
                    'message' => $status >= 500 ? 'Could not create DataCrazy checkout.' : $e->getMessage(),
                    'external_event_id' => $event->external_event_id,
                ],
            ];
        }
    }

    /**
     * @return null|array{status: int, body: array<string, mixed>}
     */
    private function buildIdempotentResponse(DataCrazyIntegrationEvent $event): ?array
    {
        if (DataCrazyIntegrationEvent::STATUS_PROCESSED === $event->status && is_array($event->response_payload)) {
            return [
                'status' => 200,
                'body' => [
                    ...$event->response_payload,
                    'idempotent' => true,
                ],
            ];
        }

        if (DataCrazyIntegrationEvent::STATUS_PROCESSING === $event->status) {
            return [
                'status' => 409,
                'body' => [
                    'success' => false,
                    'message' => 'DataCrazy event is already being processed.',
                    'external_event_id' => $event->external_event_id,
                ],
            ];
        }

        return null;
    }

    private function resolvePlan(string $planCode): SubscriptionPlan
    {
        $plan = SubscriptionPlan::findByCode($planCode);
        if (!$plan || !$plan->is_active) {
            throw new Exception('Plan code is not configured or inactive.', 422);
        }

        if (!$plan->stripe_price_id) {
            throw new Exception('Stripe price is not configured for this plan code.', 409);
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findOrCreateUser(array $payload): User
    {
        $email = Str::lower((string) $payload['email']);
        $user = User::withTrashed()->where('email', $email)->first();
        $role = $this->resolveRole($this->nullableString($payload['role'] ?? null));
        $phone = $this->nullableString($payload['phone'] ?? $payload['whatsapp'] ?? null);

        if (!$user) {
            return User::create([
                'name' => (string) $payload['name'],
                'email' => $email,
                'password' => Str::password(32),
                'role' => $role,
                'whatsapp' => $phone,
                'whatsapp_number' => $phone,
                'email_verified_at' => now(),
            ]);
        }

        if (method_exists($user, 'trashed') && $user->trashed()) {
            $user->restore();
        }

        $updates = [
            'name' => (string) $payload['name'],
            'whatsapp' => $phone ?? $user->whatsapp,
            'whatsapp_number' => $phone ?? $user->whatsapp_number,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ];

        if (isset($payload['role']) && in_array((string) $payload['role'], ['creator', 'brand', 'student'], true)) {
            $updates['role'] = (string) $payload['role'];
        }

        $user->forceFill($updates)->save();

        return $user->refresh();
    }

    private function resolveRole(?string $role): string
    {
        if ($role && in_array($role, ['creator', 'brand', 'student'], true)) {
            return $role;
        }

        $defaultRole = (string) config('services.datacrazy.default_role', 'creator');

        return in_array($defaultRole, ['creator', 'brand', 'student'], true)
            ? $defaultRole
            : 'creator';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTrialDays(array $payload): int
    {
        if (isset($payload['trial_days'])) {
            return max(0, min(30, (int) $payload['trial_days']));
        }

        return max(0, (int) config('services.stripe.subscription_trial_days', 0));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createStripeCheckoutSession(
        string $customerId,
        User $user,
        SubscriptionPlan $plan,
        DataCrazyIntegrationEvent $event,
        array $payload,
        int $trialDays
    ): Session {
        $metadata = [
            'type' => 'datacrazy_subscription',
            'user_id' => (string) $user->id,
            'plan_id' => (string) $plan->id,
            'plan_code' => (string) $plan->code,
            'datacrazy_event_id' => (string) $event->id,
            'datacrazy_external_event_id' => $event->external_event_id,
            'datacrazy_external_id' => (string) ($event->external_id ?? ''),
            'datacrazy_business_id' => (string) ($event->business_id ?? ''),
        ];

        $params = [
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'payment_method_collection' => 'always',
            'line_items' => [
                [
                    'price' => $plan->stripe_price_id,
                    'quantity' => 1,
                ],
            ],
            'mode' => 'subscription',
            'locale' => 'pt-BR',
            'success_url' => $this->buildSuccessUrl($this->nullableString($payload['redirect_url'] ?? null)),
            'cancel_url' => $this->buildCancelUrl(),
            'metadata' => $metadata,
            'subscription_data' => [
                'metadata' => $metadata,
            ],
        ];

        if ($trialDays > 0) {
            $params['subscription_data']['trial_period_days'] = $trialDays;
        }

        return $this->stripeWrapper->createCheckoutSession($params);
    }

    private function buildSuccessUrl(?string $redirectUrl): string
    {
        $successUrl = $redirectUrl
            ?: config('services.datacrazy.success_url')
            ?: rtrim((string) config('app.frontend_url', 'http://localhost:5000'), '/') . '/dashboard/subscription?success=true&session_id={CHECKOUT_SESSION_ID}';

        if (str_contains($successUrl, '{CHECKOUT_SESSION_ID}')) {
            return $successUrl;
        }

        $separator = str_contains($successUrl, '?') ? '&' : '?';

        return $successUrl . $separator . 'session_id={CHECKOUT_SESSION_ID}';
    }

    private function buildCancelUrl(): string
    {
        return config('services.datacrazy.cancel_url')
            ?: rtrim((string) config('app.frontend_url', 'http://localhost:5000'), '/') . '/dashboard/subscription?canceled=true';
    }

    private function assertRedirectAllowed(?string $redirectUrl): void
    {
        if (!$redirectUrl) {
            return;
        }

        $host = parse_url($redirectUrl, PHP_URL_HOST);
        $allowedHosts = config('services.datacrazy.allowed_redirect_hosts', []);

        if (!$host || !is_array($allowedHosts) || [] === $allowedHosts) {
            throw new Exception('Redirect URL is not allowed.', 422);
        }

        if (!in_array($host, $allowedHosts, true)) {
            throw new Exception('Redirect URL is not allowed.', 422);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}

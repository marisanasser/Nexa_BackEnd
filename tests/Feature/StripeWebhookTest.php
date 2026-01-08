<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payment\Repositories\WebhookEventRepository;
use App\Domain\Payment\Services\PaymentMethodService;
use App\Models\Payment\WebhookEvent;
use App\Models\User\User;


use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class StripeWebhookTest extends TestCase
{
    // DB persistence disabled due to missing SQLite driver

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.webhook_secret', 'whsec_test_secret');

        // Mock WebhookEventRepository for all tests to avoid DB calls
        $this->mock(WebhookEventRepository::class, function ($mock): void {
            $mock->shouldReceive('findByStripeEventId')->andReturnNull();
            $mock->shouldReceive('create')->andReturn(new WebhookEvent());
            $mock->shouldReceive('updateStatus')->andReturnNull();
            $mock->shouldReceive('updateStatusByStripeEventId')->andReturnNull();
        });
    }

    public function testWebhookHandlesSetupCheckoutSessionForBrand(): void
    {
        // 1. Arrange
        $user = new User();
        $user->forceFill([
            'id' => 1,
            'role' => 'brand',
            'stripe_customer_id' => 'cus_test_123',
        ]);

        $payload = [
            'id' => 'evt_test_123',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_123',
                    'object' => 'checkout.session',
                    'customer' => 'cus_test_123',
                    'mode' => 'setup',
                    'setup_intent' => 'seti_test_123',
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'type' => 'payment_method_setup',
                    ],
                ],
            ],
        ];

        $timestamp = time();
        $payloadJson = json_encode($payload);
        $signature = $this->generateSignature($payloadJson, 'whsec_test_secret', $timestamp);

        // Mock PaymentService
        $this->mock(PaymentMethodService::class, function ($mock): void {
            $mock->shouldReceive('handleGeneralSetupCheckout')
                ->once()
                ->with(Mockery::on(fn($arg) => 'cs_test_123' === $arg->id))
            ;
        });

        // 2. Act
        $response = $this->postJson('/api/stripe/webhook', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        // 3. Assert
        $response->assertStatus(200);
    }

    public function testWebhookRejectsInvalidSignature(): void
    {
        $payload = ['id' => 'evt_test_fake'];

        $response = $this->postJson('/api/stripe/webhook', $payload, [
            'Stripe-Signature' => 't=123,v1=invalid_signature',
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Invalid signature'])
        ;
    }

    public function testWebhookIgnoresSetupSessionIfUserNotFound(): void
    {
        // 1. Arrange
        $payload = [
            'id' => 'evt_test_123',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_unknown',
                    'object' => 'checkout.session',
                    'customer' => 'cus_test_unknown',
                    'mode' => 'setup',
                    'setup_intent' => 'seti_test_unknown',
                    'metadata' => [
                        'user_id' => '999',
                        'type' => 'payment_method_setup',
                    ],
                ],
            ],
        ];

        $timestamp = time();
        $payloadJson = json_encode($payload);
        $signature = $this->generateSignature($payloadJson, 'whsec_test_secret', $timestamp);

        // Mock PaymentService
        $this->mock(PaymentMethodService::class, function ($mock): void {
            $mock->shouldReceive('handleGeneralSetupCheckout')
                ->once()
                ->with(Mockery::on(fn($arg) => 'cs_test_unknown' === $arg->id))
            ;
        });

        // 2. Act
        $response = $this->postJson('/api/stripe/webhook', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        // 3. Assert
        $response->assertStatus(200);
    }

    private function generateSignature($payload, $secret, $timestamp)
    {
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }
}

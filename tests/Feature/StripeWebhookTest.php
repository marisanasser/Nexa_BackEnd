<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\BrandPaymentMethod;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Stripe\Checkout\Session;
use Illuminate\Support\Facades\Event;
use Mockery;
use App\Services\PaymentService;
use App\Repositories\WebhookEventRepository;

class StripeWebhookTest extends TestCase
{
    // DB persistence disabled due to missing SQLite driver
    
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.webhook_secret', 'whsec_test_secret');
        
        // Mock WebhookEventRepository for all tests to avoid DB calls
        $this->mock(WebhookEventRepository::class, function ($mock) {
            $mock->shouldReceive('findByStripeEventId')->andReturnNull();
            $mock->shouldReceive('create')->andReturn(new WebhookEvent());
            $mock->shouldReceive('updateStatus')->andReturnNull();
            $mock->shouldReceive('updateStatusByStripeEventId')->andReturnNull();
        });
    }

    public function test_webhook_handles_setup_checkout_session_for_brand()
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
                        'user_id' => (string)$user->id,
                        'type' => 'payment_method_setup'
                    ]
                ]
            ]
        ];

        $timestamp = time();
        $payloadJson = json_encode($payload);
        $signature = $this->generateSignature($payloadJson, 'whsec_test_secret', $timestamp);

        // Mock PaymentService
        $this->mock(PaymentService::class, function ($mock) use ($user) {
            // Mock finding the user
            $mock->shouldReceive('findUserById')
                ->once()
                ->with(Mockery::on(function ($arg) use ($user) {
                    return (int)$arg === $user->id;
                }))
                ->andReturn($user);

            $mock->shouldReceive('handleSetupSessionSuccess')
                ->once()
                ->with('cs_test_123', Mockery::on(function ($arg) use ($user) {
                    return $arg->id === $user->id;
                }))
                ->andReturn(['payment_method' => new BrandPaymentMethod()]);
        });

        // 2. Act
        $response = $this->postJson('/api/stripe/webhook', $payload, [
            'Stripe-Signature' => $signature,
        ]);

        // 3. Assert
        $response->assertStatus(200);
    }

    public function test_webhook_rejects_invalid_signature()
    {
        $payload = ['id' => 'evt_test_fake'];
        
        $response = $this->postJson('/api/stripe/webhook', $payload, [
            'Stripe-Signature' => 't=123,v1=invalid_signature',
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Invalid signature']);
    }

    public function test_webhook_ignores_setup_session_if_user_not_found()
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
                        'type' => 'payment_method_setup'
                    ]
                ]
            ]
        ];

        $timestamp = time();
        $payloadJson = json_encode($payload);
        $signature = $this->generateSignature($payloadJson, 'whsec_test_secret', $timestamp);

        // Mock PaymentService
        $this->mock(PaymentService::class, function ($mock) {
            $mock->shouldReceive('findUserById')
                ->once()
                ->with(999) // Expect int 999
                ->andReturnNull();

            // Should NOT call handleSetupSessionSuccess
            $mock->shouldReceive('handleSetupSessionSuccess')
                ->never();
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

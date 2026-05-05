<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment\DataCrazyIntegrationEvent;
use App\Models\Payment\SubscriptionPlan;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class DataCrazyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.datacrazy.integration_token', 'datacrazy-secret');
        Config::set('services.datacrazy.hmac_secret', null);
        Config::set('services.datacrazy.allowed_redirect_hosts', ['nexa.test']);
        Config::set('services.stripe.subscription_trial_days', 7);
    }

    public function testDatacrazyCheckoutRequiresIntegrationCredentials(): void
    {
        $response = $this->postJson('/api/integrations/datacrazy/checkout', $this->validPayload());

        $response
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid DataCrazy integration credentials.',
            ]);
    }

    public function testDatacrazyCheckoutCreatesUserEventAndStripeCheckout(): void
    {
        $plan = $this->createPlan();
        $this->mockStripeCheckout($plan);

        $response = $this->postJson('/api/integrations/datacrazy/checkout', $this->validPayload(), [
            'Authorization' => 'Bearer datacrazy-secret',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'checkout_url' => 'https://checkout.stripe.com/c/datacrazy',
                'checkout_session_id' => 'cs_test_datacrazy',
                'plan_code' => 'nexa_anual',
                'trial_days' => 7,
                'external_event_id' => 'dc_evt_123',
                'idempotent' => false,
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'cliente@datacrazy.test',
            'role' => 'creator',
            'stripe_customer_id' => 'cus_datacrazy',
        ]);

        $this->assertDatabaseHas('datacrazy_integration_events', [
            'external_event_id' => 'dc_evt_123',
            'status' => DataCrazyIntegrationEvent::STATUS_PROCESSED,
            'plan_code' => 'nexa_anual',
            'stripe_checkout_session_id' => 'cs_test_datacrazy',
            'subscription_plan_id' => $plan->id,
        ]);
    }

    public function testDatacrazyCheckoutIsIdempotentByExternalEventId(): void
    {
        $plan = $this->createPlan();
        $this->mockStripeCheckout($plan);

        $headers = ['Authorization' => 'Bearer datacrazy-secret'];

        $firstResponse = $this->postJson('/api/integrations/datacrazy/checkout', $this->validPayload(), $headers);
        $secondResponse = $this->postJson('/api/integrations/datacrazy/checkout', $this->validPayload(), $headers);

        $firstResponse->assertStatus(200)->assertJson(['idempotent' => false]);
        $secondResponse->assertStatus(200)->assertJson([
            'checkout_url' => 'https://checkout.stripe.com/c/datacrazy',
            'idempotent' => true,
        ]);

        $this->assertSame(1, User::where('email', 'cliente@datacrazy.test')->count());
        $this->assertSame(1, DataCrazyIntegrationEvent::where('external_event_id', 'dc_evt_123')->count());
    }

    public function testDatacrazyCheckoutAcceptsValidHmacSignature(): void
    {
        Config::set('services.datacrazy.integration_token', null);
        Config::set('services.datacrazy.hmac_secret', 'hmac-secret');

        $plan = $this->createPlan();
        $this->mockStripeCheckout($plan);

        $payload = $this->validPayload();
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payloadJson, 'hmac-secret');

        $response = $this->call(
            'POST',
            '/api/integrations/datacrazy/checkout',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_DATACRAZY_TIMESTAMP' => $timestamp,
                'HTTP_X_DATACRAZY_SIGNATURE' => 'sha256=' . $signature,
            ],
            $payloadJson
        );

        $response
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'external_event_id' => 'dc_evt_123',
            'event_type' => 'checkout_requested',
            'external_id' => 'lead_123',
            'business_id' => 'deal_456',
            'name' => 'Cliente DataCrazy',
            'email' => 'cliente@datacrazy.test',
            'phone' => '+5561999999999',
            'plan_code' => 'nexa_anual',
            'trial_days' => 7,
            'redirect_url' => 'https://nexa.test/checkout/success',
            'metadata' => [
                'pipeline_stage' => 'trial_solicitado',
                'tags' => ['trial_7_dias', 'nexa'],
            ],
        ];
    }

    private function createPlan(): SubscriptionPlan
    {
        $plan = SubscriptionPlan::where('code', 'nexa_anual')->first()
            ?? new SubscriptionPlan(['name' => 'Plano Anual', 'code' => 'nexa_anual']);

        $plan->forceFill([
            'description' => 'Plano anual Nexa',
            'price' => 238.80,
            'stripe_price_id' => 'price_nexa_anual',
            'stripe_product_id' => 'prod_nexa_anual',
            'duration_months' => 12,
            'is_active' => true,
            'features' => [],
            'sort_order' => 1,
        ])->save();

        return $plan->refresh();
    }

    private function mockStripeCheckout(SubscriptionPlan $plan): void
    {
        $this->mock(StripeWrapper::class, function ($mock) use ($plan): void {
            $mock->shouldReceive('createCustomer')
                ->once()
                ->with(Mockery::on(fn (array $params): bool => 'cliente@datacrazy.test' === $params['email']))
                ->andReturn(Customer::constructFrom(['id' => 'cus_datacrazy', 'deleted' => false]));

            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->with(Mockery::on(function (array $params) use ($plan): bool {
                    return 'cus_datacrazy' === $params['customer']
                        && 'subscription' === $params['mode']
                        && 'always' === $params['payment_method_collection']
                        && 'price_nexa_anual' === $params['line_items'][0]['price']
                        && $params['subscription_data']['trial_period_days'] === 7
                        && $params['metadata']['type'] === 'datacrazy_subscription'
                        && $params['metadata']['plan_id'] === (string) $plan->id
                        && $params['metadata']['plan_code'] === 'nexa_anual'
                        && $params['metadata']['datacrazy_external_event_id'] === 'dc_evt_123'
                        && str_contains($params['success_url'], 'session_id={CHECKOUT_SESSION_ID}');
                }))
                ->andReturn(Session::constructFrom([
                    'id' => 'cs_test_datacrazy',
                    'url' => 'https://checkout.stripe.com/c/datacrazy',
                ]));
        });
    }
}

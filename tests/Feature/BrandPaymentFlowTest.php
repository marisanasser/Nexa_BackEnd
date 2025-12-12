<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\BrandPaymentMethod;
use App\Wrappers\StripeWrapper;
use App\Repositories\PaymentRepository;
use Tests\TestCase;
use Mockery;
use Stripe\Checkout\Session;

class BrandPaymentFlowTest extends TestCase
{
    // use RefreshDatabase; // Cannot use DB due to missing driver

    protected $user;
    protected $stripeWrapper;
    protected $paymentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a Brand User instance (not persisted)
        $this->user = new User();
        $this->user->forceFill([
            'id' => 1,
            'role' => 'brand',
            'email' => 'brand@test.com',
            'name' => 'Test Brand'
        ]);
        $this->user->wasRecentlyCreated = false;

        // Mock StripeWrapper
        $this->stripeWrapper = Mockery::mock(StripeWrapper::class);
        $this->app->instance(StripeWrapper::class, $this->stripeWrapper);
        
        // Mock PaymentRepository
        $this->paymentRepository = Mockery::mock(PaymentRepository::class);
        $this->app->instance(PaymentRepository::class, $this->paymentRepository);
        
        // Handle setApiKey call in Service constructor if it happens during request
        $this->stripeWrapper->shouldReceive('setApiKey')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_brand_can_create_checkout_session()
    {
        // Expect Repository calls
        // ensureStripeCustomer calls
        // If user has no stripe_customer_id, it calls stripeWrapper->createCustomer
        // Then repo->updateUserStripeId
        
        $this->stripeWrapper->shouldReceive('createCustomer')
            ->andReturn(new \Stripe\Customer('cus_test'));
            
        $this->paymentRepository->shouldReceive('updateUserStripeId')
            ->once();

        $this->stripeWrapper->shouldReceive('createCheckoutSession')
            ->once()
            ->andReturn(Session::constructFrom(['id' => 'cs_test_123', 'url' => 'http://test.url', 'customer' => 'cus_test']));

        $response = $this->actingAs($this->user)
            ->postJson('/api/brand-payment/create-checkout-session');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'session_id' => 'cs_test_123',
                'url' => 'http://test.url'
            ]);
    }

    public function test_brand_can_save_payment_method_manually()
    {
        $this->withoutExceptionHandling();
        
        $data = [
            'card_holder_name' => 'Test Holder',
            'card_hash' => 'hash_ending_4242',
            'is_default' => true
        ];

        // Mock Repository checks
        $this->paymentRepository->shouldReceive('findBrandPaymentMethodByCardHash')
            ->with($this->user->id, 'hash_ending_4242')
            ->andReturnNull(); // No duplicate

        $mockMethod = new BrandPaymentMethod();
        $mockMethod->forceFill([
            'id' => 10,
            'user_id' => $this->user->id,
            'card_holder_name' => 'Test Holder',
            'card_last4' => '4242',
            'is_default' => true
        ]);

        $this->paymentRepository->shouldReceive('createBrandPaymentMethod')
            ->once()
            ->andReturn($mockMethod);

        $this->paymentRepository->shouldReceive('unsetDefaultPaymentMethods')->once();
        $this->paymentRepository->shouldReceive('setPaymentMethodAsDefault')->once();
        $this->paymentRepository->shouldReceive('updateUserDefaultPaymentMethod')->never(); // No stripe id

        $response = $this->actingAs($this->user)
            ->postJson('/api/brand-payment/save-method', $data);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_brand_cannot_add_duplicate_payment_method()
    {
        //$this->withoutExceptionHandling(); // Expecting 500, so handle exceptions

        $data = [
            'card_holder_name' => 'Test Holder',
            'card_hash' => 'hash_ending_4242',
            'is_default' => false
        ];

        // Mock Repository finding duplicate
        $existingMethod = new BrandPaymentMethod(['id' => 99]);
        
        $this->paymentRepository->shouldReceive('findBrandPaymentMethodByCardHash')
            ->with($this->user->id, 'hash_ending_4242')
            ->andReturn($existingMethod);

        $response = $this->actingAs($this->user)
            ->postJson('/api/brand-payment/save-method', $data);

        $response->assertStatus(500);
        $response->assertJsonFragment(['error' => 'This payment method already exists']);
    }

    public function test_brand_can_delete_payment_method()
    {
        $mockMethod = new BrandPaymentMethod();
        $mockMethod->forceFill([
            'id' => 10,
            'user_id' => $this->user->id,
            'is_default' => false,
            'is_active' => true,
            'stripe_payment_method_id' => 'pm_123'
        ]);

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->with($this->user->id, 10)
            ->andReturn($mockMethod);

        $this->paymentRepository->shouldReceive('countActiveBrandPaymentMethods')
            ->with($this->user->id)
            ->andReturn(2); // Must be > 1

        $this->paymentRepository->shouldReceive('deactivatePaymentMethod')
            ->once()
            ->with($mockMethod);

        // Not default, so no further calls expected for default reassignment
        
        $response = $this->actingAs($this->user)
            ->deleteJson('/api/brand-payment/methods', ['payment_method_id' => 10]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_brand_can_handle_checkout_success()
    {
        $sessionId = 'cs_test_success_123';
        $setupIntentId = 'seti_123';
        $paymentMethodId = 'pm_card_123';
        $customerId = 'cus_test_123';

        // Set up user with stripe customer id
        $this->user->stripe_customer_id = $customerId;

        // Construct complex Stripe objects structure
        $card = new \stdClass();
        $card->brand = 'visa';
        $card->last4 = '4242';

        $billingDetails = new \stdClass();
        $billingDetails->name = 'Test Holder';

        $paymentMethodStripe = new \stdClass();
        $paymentMethodStripe->id = $paymentMethodId;
        $paymentMethodStripe->card = $card;
        $paymentMethodStripe->billing_details = $billingDetails;

        $setupIntent = new \stdClass();
        $setupIntent->id = $setupIntentId;
        $setupIntent->payment_method = $paymentMethodStripe;

        $session = Session::constructFrom([
            'id' => $sessionId,
            'customer' => $customerId,
            'metadata' => ['user_id' => (string)$this->user->id],
        ]);
        // Manually assign setup_intent as constructFrom might convert array to object but here we inject our stdClass structure
        // Actually constructFrom handles arrays recursively.
        // Let's rely on constructFrom for cleaner code if possible, or just assign properties.
        $session->setup_intent = $setupIntent;

        $this->stripeWrapper->shouldReceive('retrieveCheckoutSession')
            ->once()
            ->with($sessionId, ['expand' => ['setup_intent.payment_method']])
            ->andReturn($session);

        // Repo interactions
        $this->paymentRepository->shouldReceive('findBrandPaymentMethodByStripeId')
            ->with($this->user->id, $paymentMethodId)
            ->andReturnNull();

        $this->paymentRepository->shouldReceive('countActiveBrandPaymentMethods')
            ->with($this->user->id)
            ->andReturn(0); // First method, so it becomes default

        $mockMethod = new BrandPaymentMethod();
        $mockMethod->forceFill([
            'id' => 11,
            'user_id' => $this->user->id,
            'card_last4' => '4242',
            'card_brand' => 'Visa',
            'is_default' => true,
            'stripe_payment_method_id' => $paymentMethodId
        ]);

        $this->paymentRepository->shouldReceive('createBrandPaymentMethod')
            ->once()
            ->andReturn($mockMethod);

        // Expect default setting logic
        $this->paymentRepository->shouldReceive('unsetDefaultPaymentMethods')->once();
        $this->paymentRepository->shouldReceive('setPaymentMethodAsDefault')->once();
        $this->paymentRepository->shouldReceive('updateUserDefaultPaymentMethod')->once();

        $response = $this->actingAs($this->user)
            ->postJson('/api/brand-payment/handle-checkout-success', ['session_id' => $sessionId]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => 11,
                    'card_last4' => '4242'
                ]
            ]);
    }
}

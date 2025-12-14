<?php

namespace Tests\Unit\Services;

use App\Models\BrandPaymentMethod;
use App\Models\User;
use App\Repositories\PaymentRepository;
use App\Services\PaymentService;
use App\Wrappers\StripeWrapper;
use Illuminate\Support\Facades\Config;
use Mockery;
use Stripe\Customer;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    protected $paymentService;

    protected $paymentRepository;

    protected $stripeWrapper;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.stripe.secret', 'sk_test_mock_key');

        $this->paymentRepository = Mockery::mock(PaymentRepository::class);
        $this->stripeWrapper = Mockery::mock(StripeWrapper::class);

        // Mock setApiKey to avoid real Stripe calls
        $this->stripeWrapper->shouldReceive('setApiKey')->with('sk_test_mock_key');

        $this->paymentService = new PaymentService($this->paymentRepository, $this->stripeWrapper);

        // Mock User
        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->user->shouldReceive('getAttribute')->with('email')->andReturn('test@example.com');
        $this->user->shouldReceive('getAttribute')->with('name')->andReturn('Test User');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_save_brand_payment_method_creates_record_via_repository()
    {
        $data = [
            'card_holder_name' => 'John Doe',
            'card_hash' => 'hash_ending_1234',
            'is_default' => true,
        ];

        $mockPaymentMethod = Mockery::mock(BrandPaymentMethod::class);
        $mockPaymentMethod->shouldReceive('getAttribute')->with('id')->andReturn(10);
        $mockPaymentMethod->shouldReceive('getAttribute')->with('stripe_payment_method_id')->andReturn('pm_123');

        // Expect duplicate check
        $this->paymentRepository->shouldReceive('findBrandPaymentMethodByCardHash')
            ->once()
            ->with(1, 'hash_ending_1234')
            ->andReturnNull();

        // Expect repository create
        $this->paymentRepository->shouldReceive('createBrandPaymentMethod')
            ->once()
            ->with(Mockery::on(function ($arg) {
                return $arg['card_holder_name'] === 'John Doe' &&
                       $arg['user_id'] === 1 &&
                       $arg['card_last4'] === '1234';
            }))
            ->andReturn($mockPaymentMethod);

        // Expect setAsDefault calls
        $this->paymentRepository->shouldReceive('unsetDefaultPaymentMethods')
            ->once()
            ->with(1, 10);

        $this->paymentRepository->shouldReceive('setPaymentMethodAsDefault')
            ->once()
            ->with($mockPaymentMethod);

        $this->paymentRepository->shouldReceive('updateUserDefaultPaymentMethod')
            ->once()
            ->with($this->user, 'pm_123');

        $result = $this->paymentService->saveBrandPaymentMethod($this->user, $data);

        $this->assertSame($mockPaymentMethod, $result);
    }

    public function test_ensure_stripe_customer_creates_new_customer_if_none_exists()
    {
        $this->user->shouldReceive('getAttribute')->with('stripe_customer_id')->andReturn(null);

        // Real Stripe Customer object
        $customer = new Customer(['id' => 'cus_new123']);

        // Mock Stripe Wrapper create
        $this->stripeWrapper->shouldReceive('createCustomer')
            ->once()
            ->andReturn($customer);

        // Expect repository update
        $this->paymentRepository->shouldReceive('updateUserStripeId')
            ->once()
            ->with($this->user, 'cus_new123');

        $customerId = $this->paymentService->ensureStripeCustomer($this->user);

        $this->assertEquals('cus_new123', $customerId);
    }

    public function test_ensure_stripe_customer_returns_existing_id()
    {
        $this->user->shouldReceive('getAttribute')->with('stripe_customer_id')->andReturn('cus_existing');

        // Real Stripe Customer object
        $customer = new Customer(['id' => 'cus_existing']);

        // Mock Stripe Wrapper retrieve
        $this->stripeWrapper->shouldReceive('retrieveCustomer')
            ->once()
            ->with('cus_existing')
            ->andReturn($customer);

        $customerId = $this->paymentService->ensureStripeCustomer($this->user);

        $this->assertEquals('cus_existing', $customerId);
    }

    public function test_create_setup_checkout_session()
    {
        $this->user->shouldReceive('getAttribute')->with('stripe_customer_id')->andReturn('cus_123');
        $this->user->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // Mock ensureStripeCustomer internal logic (retrieve)
        $this->stripeWrapper->shouldReceive('retrieveCustomer')
            ->once()
            ->with('cus_123')
            ->andReturn(new Customer(['id' => 'cus_123']));

        $this->stripeWrapper->shouldReceive('createCheckoutSession')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['customer'] === 'cus_123' &&
                       $args['mode'] === 'setup';
            }))
            ->andReturn(new \Stripe\Checkout\Session(['id' => 'cs_test_123']));

        $session = $this->paymentService->createSetupCheckoutSession($this->user);

        $this->assertEquals('cs_test_123', $session->id);
    }

    public function test_delete_brand_payment_method_success()
    {
        $paymentMethodId = 10;
        $stripePaymentMethodId = 'pm_123';

        $mockPaymentMethod = Mockery::mock(BrandPaymentMethod::class)->makePartial();
        $mockPaymentMethod->id = $paymentMethodId;
        $mockPaymentMethod->user_id = 1;
        $mockPaymentMethod->stripe_payment_method_id = $stripePaymentMethodId;
        $mockPaymentMethod->is_default = false;
        $mockPaymentMethod->shouldReceive('getAttribute')->with('is_default')->andReturn(false);
        $mockPaymentMethod->shouldReceive('getAttribute')->with('stripe_payment_method_id')->andReturn($stripePaymentMethodId);

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->with(1, $paymentMethodId)
            ->andReturn($mockPaymentMethod);

        $this->paymentRepository->shouldReceive('countActiveBrandPaymentMethods')
            ->once()
            ->with(1)
            ->andReturn(2);

        $this->paymentRepository->shouldReceive('deactivatePaymentMethod')
            ->once()
            ->with($mockPaymentMethod);

        $this->paymentService->deleteBrandPaymentMethod($this->user, $paymentMethodId);

        $this->assertTrue(true);
    }

    public function test_get_brand_payment_method_success()
    {
        $paymentMethodId = 10;
        $mockPaymentMethod = new BrandPaymentMethod(['id' => $paymentMethodId]);

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->with(1, $paymentMethodId)
            ->andReturn($mockPaymentMethod);

        $result = $this->paymentService->getBrandPaymentMethod($this->user, $paymentMethodId);

        $this->assertEquals($mockPaymentMethod, $result);
    }

    public function test_get_brand_payment_method_throws_exception_if_not_found()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Payment method not found');

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->with(1, 999)
            ->andReturn(null);

        $this->paymentService->getBrandPaymentMethod($this->user, 999);
    }

    public function test_delete_brand_payment_method_throws_exception_if_not_found()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Payment method not found');

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->with(1, 999)
            ->andReturn(null);

        $this->paymentService->deleteBrandPaymentMethod($this->user, 999);
    }

    public function test_delete_brand_payment_method_throws_exception_if_only_one_left()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete the only payment method');

        $mockPaymentMethod = Mockery::mock(BrandPaymentMethod::class);

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->andReturn($mockPaymentMethod);

        $this->paymentRepository->shouldReceive('countActiveBrandPaymentMethods')
            ->once()
            ->with(1)
            ->andReturn(1);

        $this->paymentService->deleteBrandPaymentMethod($this->user, 10);
    }

    public function test_handle_setup_session_success_creates_payment_method()
    {
        $sessionId = 'cs_test_success';
        $stripeCustomerId = 'cus_123';
        $stripePaymentMethodId = 'pm_new_123';
        $setupIntentId = 'seti_123';

        // Mock Stripe Objects using constructFrom to ensure proper structure
        $mockSession = \Stripe\Checkout\Session::constructFrom([
            'id' => $sessionId,
            'metadata' => ['user_id' => 1],
            'customer' => ['id' => $stripeCustomerId],
            'setup_intent' => [
                'id' => $setupIntentId,
                'payment_method' => [
                    'id' => $stripePaymentMethodId,
                    'card' => ['brand' => 'visa', 'last4' => '4242'],
                    'billing_details' => ['name' => 'John Doe'],
                ],
            ],
        ]);

        $this->stripeWrapper->shouldReceive('retrieveCheckoutSession')
            ->once()
            ->with($sessionId, ['expand' => ['setup_intent.payment_method']])
            ->andReturn($mockSession);

        $this->paymentRepository->shouldReceive('findBrandPaymentMethodByStripeId')
            ->once()
            ->with(1, $stripePaymentMethodId)
            ->andReturn(null);

        $this->paymentRepository->shouldReceive('countActiveBrandPaymentMethods')
            ->once()
            ->with(1)
            ->andReturn(0); // No active methods, so this will be default

        $mockCreatedPaymentMethod = Mockery::mock(BrandPaymentMethod::class);
        $mockCreatedPaymentMethod->shouldReceive('getAttribute')->with('id')->andReturn(100);
        $mockCreatedPaymentMethod->shouldReceive('getAttribute')->with('stripe_payment_method_id')->andReturn($stripePaymentMethodId);

        $this->paymentRepository->shouldReceive('createBrandPaymentMethod')
            ->once()
            ->with(Mockery::on(function ($data) use ($stripePaymentMethodId, $stripeCustomerId) {
                return $data['stripe_payment_method_id'] === $stripePaymentMethodId &&
                       $data['stripe_customer_id'] === $stripeCustomerId &&
                       $data['is_default'] === true;
            }))
            ->andReturn($mockCreatedPaymentMethod);

        // Expect setting as default
        $this->paymentRepository->shouldReceive('unsetDefaultPaymentMethods');
        $this->paymentRepository->shouldReceive('setPaymentMethodAsDefault');
        $this->paymentRepository->shouldReceive('updateUserDefaultPaymentMethod');

        $result = $this->paymentService->handleSetupSessionSuccess($sessionId, $this->user);

        $this->assertSame($mockCreatedPaymentMethod, $result['payment_method']);
    }
}

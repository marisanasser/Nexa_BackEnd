<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Payment\BrandPaymentMethod;
use App\Models\User\User;
use App\Domain\Payment\Repositories\PaymentMethodRepository as PaymentRepository;
use App\Domain\Payment\Services\PaymentMethodService as PaymentService;
use App\Domain\Payment\Services\StripeCustomerService;

use App\Wrappers\StripeWrapper;
use Exception;
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
class PaymentServiceTest extends TestCase
{
    protected $paymentService;

    protected $paymentRepository;

    protected $stripeWrapper;

    protected $customerService;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.stripe.secret', 'sk_test_mock_key');

        $this->paymentRepository = Mockery::mock(PaymentRepository::class);
        $this->stripeWrapper = Mockery::mock(StripeWrapper::class);

        // Mock setApiKey to avoid real Stripe calls
        $this->stripeWrapper->shouldReceive('setApiKey')->with('sk_test_mock_key');

        $this->customerService = Mockery::mock(StripeCustomerService::class);
        $this->paymentService = new PaymentService($this->stripeWrapper, $this->customerService);

        // Real User instance to satisfy type hints
        $this->user = new User();
        $this->user->forceFill([
            'id' => 1,
            'role' => 'brand',
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);
        $this->user->wasRecentlyCreated = false;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSaveBrandPaymentMethodCreatesRecordViaRepository(): void
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
            ->andReturnNull()
        ;

        // Expect repository create
        $this->paymentRepository->shouldReceive('createBrandPaymentMethod')
            ->once()
            ->with(Mockery::on(fn ($arg) => 'John Doe' === $arg['card_holder_name']
                       && 1 === $arg['user_id']
                       && '1234' === $arg['card_last4']))
            ->andReturn($mockPaymentMethod)
        ;

        // Expect setAsDefault calls
        $this->paymentRepository->shouldReceive('unsetDefaultPaymentMethods')
            ->once()
            ->with(1, 10)
        ;

        $this->paymentRepository->shouldReceive('setPaymentMethodAsDefault')
            ->once()
            ->with($mockPaymentMethod)
        ;

        $this->paymentRepository->shouldReceive('updateUserDefaultPaymentMethod')
            ->once()
            ->with($this->user, 'pm_123')
        ;

        $result = $this->paymentService->saveBrandPaymentMethod($this->user, $data);

        $this->assertSame($mockPaymentMethod, $result);
    }

    // Removed obsolete ensureStripeCustomer tests; logic moved to StripeCustomerService

    public function testCreateSetupCheckoutSession(): void
    {
        $this->user->shouldReceive('getAttribute')->with('stripe_customer_id')->andReturn('cus_123');
        $this->user->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // Mock ensureStripeCustomer via StripeCustomerService
        $this->customerService->shouldReceive('ensureStripeCustomer')
            ->once()
            ->with($this->user)
            ->andReturn('cus_123');

        $this->stripeWrapper->shouldReceive('createCheckoutSession')
            ->once()
            ->with(Mockery::on(fn ($args) => 'cus_123' === $args['customer']
                       && 'setup' === $args['mode']
                       && isset($args['success_url'])
                       && isset($args['cancel_url'])))
            ->andReturn(new Session(['id' => 'cs_test_123']))
        ;

        $session = $this->paymentService->createSetupCheckoutSession($this->user, 'http://success', 'http://cancel');

        $this->assertEquals('cs_test_123', $session->id);
    }

    public function testDeleteBrandPaymentMethodSuccess(): void
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
            ->andReturn($mockPaymentMethod)
        ;

        $this->paymentRepository->shouldReceive('countActiveBrandPaymentMethods')
            ->once()
            ->with(1)
            ->andReturn(2)
        ;

        $this->paymentRepository->shouldReceive('deactivatePaymentMethod')
            ->once()
            ->with($mockPaymentMethod)
        ;

        $this->paymentService->deleteBrandPaymentMethod($this->user, $paymentMethodId);

        $this->assertTrue(true);
    }

    public function testGetBrandPaymentMethodSuccess(): void
    {
        $paymentMethodId = 10;
        $mockPaymentMethod = new BrandPaymentMethod(['id' => $paymentMethodId]);

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->with(1, $paymentMethodId)
            ->andReturn($mockPaymentMethod)
        ;

        $result = $this->paymentService->getBrandPaymentMethod($this->user, $paymentMethodId);

        $this->assertEquals($mockPaymentMethod, $result);
    }

    public function testGetBrandPaymentMethodThrowsExceptionIfNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Payment method not found');

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->with(1, 999)
            ->andReturn(null)
        ;

        $this->paymentService->getBrandPaymentMethod($this->user, 999);
    }

    public function testDeleteBrandPaymentMethodThrowsExceptionIfNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Payment method not found');

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->with(1, 999)
            ->andReturn(null)
        ;

        $this->paymentService->deleteBrandPaymentMethod($this->user, 999);
    }

    public function testDeleteBrandPaymentMethodThrowsExceptionIfOnlyOneLeft(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot delete the only payment method');

        $mockPaymentMethod = Mockery::mock(BrandPaymentMethod::class);

        $this->paymentRepository->shouldReceive('findBrandPaymentMethod')
            ->once()
            ->andReturn($mockPaymentMethod)
        ;

        $this->paymentRepository->shouldReceive('countActiveBrandPaymentMethods')
            ->once()
            ->with(1)
            ->andReturn(1)
        ;

        $this->paymentService->deleteBrandPaymentMethod($this->user, 10);
    }

    public function testHandleSetupSessionSuccessCreatesPaymentMethod(): void
    {
        $sessionId = 'cs_test_success';
        $stripeCustomerId = 'cus_123';
        $stripePaymentMethodId = 'pm_new_123';
        $setupIntentId = 'seti_123';

        // Mock Stripe Objects using constructFrom to ensure proper structure
        $mockSession = Session::constructFrom([
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
            ->andReturn($mockSession)
        ;

        $this->paymentRepository->shouldReceive('findBrandPaymentMethodByStripeId')
            ->once()
            ->with(1, $stripePaymentMethodId)
            ->andReturn(null)
        ;

        $this->paymentRepository->shouldReceive('countActiveBrandPaymentMethods')
            ->once()
            ->with(1)
            ->andReturn(0) // No active methods, so this will be default
        ;

        $mockCreatedPaymentMethod = Mockery::mock(BrandPaymentMethod::class);
        $mockCreatedPaymentMethod->shouldReceive('getAttribute')->with('id')->andReturn(100);
        $mockCreatedPaymentMethod->shouldReceive('getAttribute')->with('stripe_payment_method_id')->andReturn($stripePaymentMethodId);

        $this->paymentRepository->shouldReceive('createBrandPaymentMethod')
            ->once()
            ->with(Mockery::on(fn ($data) => $data['stripe_payment_method_id'] === $stripePaymentMethodId
                       && $data['stripe_customer_id'] === $stripeCustomerId
                       && true === $data['is_default']))
            ->andReturn($mockCreatedPaymentMethod)
        ;

        // Expect setting as default
        $this->paymentRepository->shouldReceive('unsetDefaultPaymentMethods');
        $this->paymentRepository->shouldReceive('setPaymentMethodAsDefault');
        $this->paymentRepository->shouldReceive('updateUserDefaultPaymentMethod');

        $result = $this->paymentService->handleSetupSessionSuccess($sessionId, $this->user);

        $this->assertSame($mockCreatedPaymentMethod, $result['payment_method']);
    }
}

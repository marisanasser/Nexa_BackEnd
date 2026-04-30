<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Payment\BrandPaymentMethod;
use App\Models\User\User;
use App\Domain\Payment\Services\PaymentMethodService as PaymentService;
use App\Domain\Payment\Services\StripeCustomerService;

use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class PaymentServiceTest extends TestCase
{
    protected $paymentService;

    protected $stripeWrapper;

    protected $customerService;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.stripe.secret', 'sk_test_mock_key');
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->createBrandPaymentMethodsTable();

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

    private function createBrandPaymentMethodsTable(): void
    {
        Schema::dropIfExists('brand_payment_methods');
        Schema::create('brand_payment_methods', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('pagarme_customer_id')->nullable();
            $table->string('pagarme_card_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_payment_method_id')->nullable();
            $table->string('stripe_setup_intent_id')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last4')->nullable();
            $table->string('card_holder_name')->nullable();
            $table->string('card_hash')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSaveBrandPaymentMethodCreatesRecord(): void
    {
        $data = [
            'stripe_payment_method_id' => 'pm_123',
            'card_brand' => 'visa',
            'card_last_four' => '1234',
            'card_holder_name' => 'John Doe',
        ];

        $this->customerService->shouldReceive('ensureStripeCustomer')
            ->once()
            ->with($this->user)
            ->andReturn('cus_123')
        ;

        $this->stripeWrapper->shouldReceive('attachPaymentMethodToCustomer')
            ->once()
            ->with('pm_123', 'cus_123')
        ;

        $result = $this->paymentService->saveBrandPaymentMethod($this->user, $data);

        $this->assertSame(1, $result->user_id);
        $this->assertSame('pm_123', $result->stripe_payment_method_id);
        $this->assertSame('visa', $result->card_brand);
        $this->assertSame('1234', $result->card_last4);
        $this->assertSame('John Doe', $result->card_holder_name);
        $this->assertTrue($result->is_default);
        $this->assertTrue($result->is_active);
    }

    // Removed obsolete ensureStripeCustomer tests; logic moved to StripeCustomerService

    public function testCreateSetupCheckoutSession(): void
    {
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
        $stripePaymentMethodId = 'pm_123';

        $paymentMethod = BrandPaymentMethod::create([
            'user_id' => 1,
            'stripe_payment_method_id' => $stripePaymentMethodId,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->stripeWrapper->shouldReceive('detachPaymentMethod')
            ->once()
            ->with($stripePaymentMethodId)
        ;

        $this->paymentService->deleteBrandPaymentMethod($this->user, $paymentMethod->id);

        $this->assertFalse($paymentMethod->fresh()->is_active);
    }

    public function testGetBrandPaymentMethodSuccess(): void
    {
        $paymentMethod = BrandPaymentMethod::create([
            'user_id' => 1,
            'stripe_payment_method_id' => 'pm_123',
            'is_active' => true,
        ]);

        $result = $this->paymentService->getBrandPaymentMethod($this->user, $paymentMethod->id);

        $this->assertTrue($paymentMethod->is($result));
    }

    public function testGetBrandPaymentMethodThrowsExceptionIfNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Payment method not found');

        $this->paymentService->getBrandPaymentMethod($this->user, 999);
    }

    public function testDeleteBrandPaymentMethodThrowsExceptionIfNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Payment method not found');

        $this->paymentService->deleteBrandPaymentMethod($this->user, 999);
    }

    public function testDeleteDefaultBrandPaymentMethodPromotesNextActiveMethod(): void
    {
        $defaultMethod = BrandPaymentMethod::create([
            'user_id' => 1,
            'stripe_payment_method_id' => 'pm_default',
            'is_default' => true,
            'is_active' => true,
        ]);
        $nextMethod = BrandPaymentMethod::create([
            'user_id' => 1,
            'stripe_payment_method_id' => 'pm_next',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->stripeWrapper->shouldReceive('detachPaymentMethod')
            ->once()
            ->with('pm_default')
        ;

        $this->paymentService->deleteBrandPaymentMethod($this->user, $defaultMethod->id);

        $this->assertFalse($defaultMethod->fresh()->is_active);
        $this->assertTrue($nextMethod->fresh()->is_default);
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
                'status' => 'succeeded',
                'payment_method' => [
                    'id' => $stripePaymentMethodId,
                    'type' => 'card',
                    'card' => ['brand' => 'visa', 'last4' => '4242'],
                    'billing_details' => ['name' => 'John Doe'],
                ],
            ],
        ]);

        $this->stripeWrapper->shouldReceive('retrieveCheckoutSession')
            ->once()
            ->with($sessionId, ['expand' => ['setup_intent', 'setup_intent.payment_method']])
            ->andReturn($mockSession)
        ;

        $this->customerService->shouldReceive('ensureStripeCustomer')
            ->once()
            ->with($this->user)
            ->andReturn($stripeCustomerId)
        ;

        $this->stripeWrapper->shouldReceive('attachPaymentMethodToCustomer')
            ->once()
            ->with($stripePaymentMethodId, $stripeCustomerId)
        ;

        $result = $this->paymentService->handleSetupSessionSuccess($sessionId, $this->user);

        $this->assertSame('brand_payment_method', $result['type']);
        $this->assertSame($stripePaymentMethodId, $result['payment_method']->stripe_payment_method_id);
        $this->assertTrue($result['payment_method']->is_default);
    }
}

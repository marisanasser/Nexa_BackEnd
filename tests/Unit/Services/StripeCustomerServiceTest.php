<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\Payment\Services\StripeCustomerService;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Exception;
use Mockery;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;
use Tests\TestCase;

class StripeCustomerServiceTest extends TestCase
{
    private StripeWrapper $stripeWrapper;
    private StripeCustomerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeWrapper = Mockery::mock(StripeWrapper::class);
        $this->service = new StripeCustomerService($this->stripeWrapper);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testEnsureStripeCustomerReturnsExistingCustomerWhenValid(): void
    {
        $user = $this->makeUser([
            'stripe_customer_id' => 'cus_existing_123',
        ]);

        $this->stripeWrapper->shouldReceive('retrieveCustomer')
            ->once()
            ->with('cus_existing_123')
            ->andReturn(Customer::constructFrom([
                'id' => 'cus_existing_123',
                'deleted' => false,
            ]))
        ;

        $this->stripeWrapper->shouldReceive('createCustomer')->never();

        $customerId = $this->service->ensureStripeCustomer($user);

        $this->assertSame('cus_existing_123', $customerId);
    }

    public function testEnsureStripeCustomerRecreatesMissingCustomer(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill([
            'id' => 466,
            'role' => 'brand',
            'email' => 'brand.teste@nexacreators.com.br',
            'name' => 'Brand Teste',
            'stripe_customer_id' => 'cus_missing_123',
        ]);
        $user->shouldReceive('update')
            ->once()
            ->with(['stripe_customer_id' => 'cus_new_123'])
            ->andReturn(true)
        ;

        $this->stripeWrapper->shouldReceive('retrieveCustomer')
            ->once()
            ->with('cus_missing_123')
            ->andThrow(InvalidRequestException::factory(
                "No such customer: 'cus_missing_123'",
                404,
                null,
                ['error' => ['code' => 'resource_missing']],
                null,
                'resource_missing'
            ))
        ;

        $this->stripeWrapper->shouldReceive('createCustomer')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return 'brand.teste@nexacreators.com.br' === ($payload['email'] ?? null)
                    && 'Brand Teste' === ($payload['name'] ?? null)
                    && '466' === ($payload['metadata']['user_id'] ?? null)
                    && 'brand' === ($payload['metadata']['role'] ?? null);
            }))
            ->andReturn(Customer::constructFrom([
                'id' => 'cus_new_123',
                'deleted' => false,
            ]))
        ;

        $customerId = $this->service->ensureStripeCustomer($user);

        $this->assertSame('cus_new_123', $customerId);
    }

    public function testEnsureStripeCustomerThrowsForNonRecoverableValidationError(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to validate Stripe customer');

        $user = $this->makeUser([
            'stripe_customer_id' => 'cus_existing_123',
        ]);

        $this->stripeWrapper->shouldReceive('retrieveCustomer')
            ->once()
            ->with('cus_existing_123')
            ->andThrow(InvalidRequestException::factory(
                'Invalid customer id format',
                400,
                null,
                ['error' => ['code' => 'parameter_invalid_empty']],
                null,
                'parameter_invalid_empty'
            ))
        ;

        $this->stripeWrapper->shouldReceive('createCustomer')->never();

        $this->service->ensureStripeCustomer($user);
    }

    private function makeUser(array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'id' => 1,
            'role' => 'brand',
            'email' => 'test@example.com',
            'name' => 'Test User',
            'stripe_customer_id' => null,
        ], $attributes));

        return $user;
    }
}

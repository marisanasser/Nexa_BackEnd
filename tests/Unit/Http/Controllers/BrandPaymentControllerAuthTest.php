<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Domain\Payment\Services\PaymentMethodService;
use App\Http\Controllers\Payment\BrandPaymentController;
use App\Models\User\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;
use Tests\Unit\Services\PaymentServiceTest;

/**
 * @internal
 *
 * @coversNothing
 */
class BrandPaymentControllerAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Do NOT set up default Auth mock here to avoid static pollution
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCreateCheckoutSessionFailsIfNotBrand(): void
    {
        $paymentService = Mockery::mock(PaymentMethodService::class);
        $controller = new BrandPaymentController($paymentService);

        $nonBrandUser = Mockery::mock(User::class)->makePartial();
        $nonBrandUser->shouldReceive('isBrand')->andReturn(false);
        $nonBrandUser->shouldReceive('getAttribute')->with('id')->andReturn(2);

        // Mock Auth facade locally
        Auth::shouldReceive('user')->andReturn($nonBrandUser);

        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $request = new Request();
        $response = $controller->createCheckoutSession($request);

        $this->assertEquals(403, $response->getStatusCode());
    }
}

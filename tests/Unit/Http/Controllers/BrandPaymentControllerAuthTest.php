<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\BrandPaymentController;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

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

    public function test_create_checkout_session_fails_if_not_brand()
    {
        $paymentService = Mockery::mock(PaymentService::class);
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Payment\BrandPaymentController;
use App\Http\Requests\StoreBrandPaymentMethodRequest;
use App\Models\Payment\BrandPaymentMethod;
use App\Domain\Payment\Services\PaymentMethodService;
use App\Models\User\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class BrandPaymentControllerTest extends TestCase
{
    protected $controller;

    protected $paymentService;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentService = Mockery::mock(PaymentMethodService::class);
        $this->controller = new BrandPaymentController($this->paymentService);

        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->user->shouldReceive('getAttribute')->with('role')->andReturn('brand');
        $this->user->shouldReceive('isBrand')->andReturn(true);

        // Mock Auth facade
        Auth::shouldReceive('user')->andReturn($this->user);
        Auth::shouldReceive('id')->andReturn(1);

        // Mock Log facade to avoid errors
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSavePaymentMethodSuccess(): void
    {
        $requestData = [
            'card_holder_name' => 'John Doe',
            'is_default' => true,
            'card_hash' => 'some_hash',
        ];

        $request = Mockery::mock(StoreBrandPaymentMethodRequest::class);
        $request->shouldReceive('boolean')->with('is_default')->andReturn(true);
        $request->shouldReceive('validated')->andReturnUsing(function () use ($requestData) {
            return $requestData;
        });

        $mockPaymentMethod = new BrandPaymentMethod([
            'id' => 10,
            'card_holder_name' => 'John Doe',
        ]);
        $mockPaymentMethod->id = 10; // Explicit set since constructor might not set ID if guarded

        $this->paymentService->shouldReceive('saveBrandPaymentMethod')
            ->once()
            ->with($this->user, $requestData)
            ->andReturn($mockPaymentMethod)
        ;

        $response = $this->controller->savePaymentMethod($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Payment method added successfully', $data['message']);
        $this->assertEquals(10, $data['data']['id']);
    }

    public function testCreateCheckoutSessionSuccess(): void
    {
        $request = new Request();

        $mockSession = new Session(['id' => 'cs_test_123']);
        $mockSession->url = 'http://test-url.com';
        $mockSession->customer = 'cus_123';

        $this->paymentService->shouldReceive('createSetupCheckoutSession')
            ->once()
            ->with($this->user, Mockery::type('string'), Mockery::type('string'))
            ->andReturn($mockSession)
        ;

        $response = $this->controller->createCheckoutSession($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertEquals('cs_test_123', $data['session_id']);
        $this->assertEquals('http://test-url.com', $data['url']);
    }

    public function testDeletePaymentMethodSuccess(): void
    {
        $requestData = ['payment_method_id' => 10];
        $request = new Request($requestData);

        // Mock Validator
        Validator::shouldReceive('make')->once()->andReturnUsing(function ($data, $rules) {
            $validator = Mockery::mock(\Illuminate\Validation\Validator::class);
            $validator->shouldReceive('fails')->andReturn(false);

            return $validator;
        });

        $this->paymentService->shouldReceive('deleteBrandPaymentMethod')
            ->once()
            ->with($this->user, 10)
        ;

        $response = $this->controller->deletePaymentMethod($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
    }

    public function testSetDefaultPaymentMethodSuccess(): void
    {
        $requestData = ['payment_method_id' => 10];
        $request = new Request($requestData);

        // Mock Validator
        Validator::shouldReceive('make')->once()->andReturnUsing(function ($data, $rules) {
            $validator = Mockery::mock(\Illuminate\Validation\Validator::class);
            $validator->shouldReceive('fails')->andReturn(false);

            return $validator;
        });

        $mockPaymentMethod = new BrandPaymentMethod(['id' => 10]);

        $this->paymentService->shouldReceive('getBrandPaymentMethod')
            ->once()
            ->with($this->user, 10)
            ->andReturn($mockPaymentMethod)
        ;

        $this->paymentService->shouldReceive('setAsDefault')
            ->once()
            ->with($this->user, $mockPaymentMethod)
        ;

        $response = $this->controller->setDefaultPaymentMethod($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
    }

    // Scenario de autorização coberto em BrandPaymentControllerAuthTest
}

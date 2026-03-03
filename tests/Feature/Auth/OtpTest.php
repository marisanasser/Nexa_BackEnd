<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class OtpTest extends TestCase
{
    use RefreshDatabase;

    public function testOtpSendReturnsServiceUnavailableWhenMailSendFails(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->with('otp-failure@example.com')
            ->andReturnSelf();
        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new Exception('SES unavailable'));

        $response = $this->postJson('/api/otp/send', [
            'contact' => 'otp-failure@example.com',
            'type' => 'email',
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function testOtpSendReturnsSuccessWhenMailIsDispatched(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/otp/send', [
            'contact' => 'otp-success@example.com',
            'type' => 'email',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CreatorBalance;
use App\Models\User\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class WithdrawalFeeTest extends TestCase
{
    use RefreshDatabase;

    public function testWithdrawalPlatformFeeIsSetCorrectly(): void
    {
        $withdrawalMethod = WithdrawalMethod::create([
            'code' => 'test_method',
            'name' => 'Test Method',
            'description' => 'Test withdrawal method',
            'min_amount' => 10.00,
            'max_amount' => 1000.00,
            'processing_time' => '1-2 days',
            'fee' => 10.00,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'role' => 'creator',
        ]);

        CreatorBalance::create([
            'creator_id' => $user->id,
            'available_balance' => 1000.00,
            'pending_balance' => 0,
            'total_earned' => 1000.00,
            'total_withdrawn' => 0,
        ]);

        $withdrawal = Withdrawal::create([
            'creator_id' => $user->id,
            'amount' => 100.00,
            'platform_fee' => 5.00,
            'fixed_fee' => 5.00,
            'withdrawal_method' => 'test_method',
            'withdrawal_details' => [],
            'status' => 'pending',
        ]);

        $this->assertEquals(5.00, $withdrawal->platform_fee);
        $this->assertEquals(5.00, $withdrawal->fixed_fee);

        $this->assertEquals(10.00, $withdrawal->percentage_fee);
        $this->assertEquals(10.00, $withdrawal->percentage_fee_amount);

        $this->assertEquals(5.00, $withdrawal->platform_fee_amount);

        $this->assertEquals(20.00, $withdrawal->total_fees);

        $this->assertEquals(80.00, $withdrawal->net_amount);
    }

    public function testWithdrawalFormattedFeesAreCorrect(): void
    {
        $withdrawalMethod = WithdrawalMethod::create([
            'code' => 'test_method_2',
            'name' => 'Test Method 2',
            'description' => 'Test withdrawal method with 5% fee',
            'min_amount' => 10.00,
            'max_amount' => 1000.00,
            'processing_time' => '1-2 days',
            'fee' => 5.00,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $user = User::create([
            'name' => 'Test User 2',
            'email' => 'test2@example.com',
            'password' => bcrypt('password'),
            'role' => 'creator',
        ]);

        CreatorBalance::create([
            'creator_id' => $user->id,
            'available_balance' => 1000.00,
            'pending_balance' => 0,
            'total_earned' => 1000.00,
            'total_withdrawn' => 0,
        ]);

        $withdrawal = Withdrawal::create([
            'creator_id' => $user->id,
            'amount' => 200.00,
            'platform_fee' => 5.00,
            'fixed_fee' => 5.00,
            'withdrawal_method' => 'test_method_2',
            'withdrawal_details' => [],
            'status' => 'pending',
        ]);

        $this->assertEquals('5.00%', $withdrawal->formatted_platform_fee);
        $this->assertEquals('R$ 10,00', $withdrawal->formatted_platform_fee_amount);
        $this->assertEquals('R$ 5,00', $withdrawal->formatted_fixed_fee);
        $this->assertEquals('R$ 10,00', $withdrawal->formatted_percentage_fee_amount);
        $this->assertEquals('R$ 25,00', $withdrawal->formatted_total_fees);
        $this->assertEquals('R$ 175,00', $withdrawal->formatted_net_amount);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payment\Actions\CreateWithdrawalAction;
use App\Domain\Payment\Services\ContractPaymentService;
use App\Domain\Payment\Services\StripeCustomerService;
use App\Models\Contract\Contract;
use App\Models\Payment\CreatorBalance;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Withdrawal;
use App\Models\User\User;
use App\Wrappers\StripeWrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Stripe\Account;
use Tests\TestCase;

class PaymentFlowRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCreatorBalanceAggregatesReturnFloatValues(): void
    {
        $creator = User::factory()->create(['role' => 'creator']);
        $brand = User::factory()->create(['role' => 'brand']);

        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'budget' => 5.00,
            'platform_fee' => 0.25,
            'creator_amount' => 4.75,
        ]);

        $balance = CreatorBalance::create([
            'creator_id' => $creator->id,
            'available_balance' => 0,
            'pending_balance' => 0,
            'total_earned' => 0,
            'total_withdrawn' => 0,
        ]);

        JobPayment::create([
            'contract_id' => $contract->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'total_amount' => 5.00,
            'platform_fee' => 0.25,
            'creator_amount' => 4.75,
            'payment_method' => 'stripe_escrow',
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        Withdrawal::create([
            'creator_id' => $creator->id,
            'amount' => 1.25,
            'withdrawal_method' => 'pix',
            'withdrawal_details' => [],
            'status' => 'pending',
        ]);

        Withdrawal::create([
            'creator_id' => $creator->id,
            'amount' => 2.50,
            'withdrawal_method' => 'pix',
            'withdrawal_details' => [],
            'status' => 'processing',
        ]);

        $this->assertIsFloat($balance->getEarningsThisMonth());
        $this->assertSame(4.75, $balance->getEarningsThisMonth());

        $this->assertIsFloat($balance->getEarningsThisYear());
        $this->assertSame(4.75, $balance->getEarningsThisYear());

        $this->assertIsFloat($balance->pending_withdrawals_amount);
        $this->assertSame(3.75, $balance->pending_withdrawals_amount);
    }

    public function testWithdrawalReservesFundsAndOnlyMarksWithdrawnOnCompletion(): void
    {
        $creator = User::factory()->create(['role' => 'creator']);

        $balance = CreatorBalance::create([
            'creator_id' => $creator->id,
            'available_balance' => 100.00,
            'pending_balance' => 0,
            'total_earned' => 100.00,
            'total_withdrawn' => 0,
        ]);

        $stripe = Mockery::mock(StripeWrapper::class);
        $stripe->shouldReceive('setApiKey')->andReturnNull();

        $action = new CreateWithdrawalAction($stripe);

        $firstRequest = $action->execute(
            user: $creator,
            amount: 40.00,
            withdrawalMethodCode: 'pix',
            withdrawalMethod: null,
            dynamicMethod: null,
            withdrawalDetails: []
        );

        $this->assertTrue($firstRequest['success']);

        $balance->refresh();
        $this->assertSame(60.00, (float) $balance->available_balance);
        $this->assertSame(0.00, (float) $balance->total_withdrawn);

        $firstWithdrawal = $firstRequest['withdrawal'];
        $firstWithdrawal->update(['status' => 'completed']);

        $updateCreatorBalance = new ReflectionMethod(Withdrawal::class, 'updateCreatorBalance');
        $updateCreatorBalance->setAccessible(true);
        $updateCreatorBalance->invoke($firstWithdrawal->fresh());

        $balance->refresh();
        $this->assertSame(60.00, (float) $balance->available_balance);
        $this->assertSame(40.00, (float) $balance->total_withdrawn);

        $secondRequest = $action->execute(
            user: $creator,
            amount: 10.00,
            withdrawalMethodCode: 'pix',
            withdrawalMethod: null,
            dynamicMethod: null,
            withdrawalDetails: []
        );

        $this->assertTrue($secondRequest['success']);

        $balance->refresh();
        $this->assertSame(50.00, (float) $balance->available_balance);
        $this->assertSame(40.00, (float) $balance->total_withdrawn);

        $this->assertTrue($secondRequest['withdrawal']->cancel('manual test'));

        $balance->refresh();
        $this->assertSame(60.00, (float) $balance->available_balance);
        $this->assertSame(40.00, (float) $balance->total_withdrawn);
    }

    public function testContractPaymentIsHeldUntilReleaseAndThenBecomesWithdrawable(): void
    {
        $creator = User::factory()->create(['role' => 'creator']);
        $brand = User::factory()->create(['role' => 'brand']);

        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'status' => 'active',
            'workflow_status' => 'active',
            'budget' => 5.00,
            'platform_fee' => 0.25,
            'creator_amount' => 4.75,
        ]);

        JobPayment::create([
            'contract_id' => $contract->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'total_amount' => 5.00,
            'platform_fee' => 0.25,
            'creator_amount' => 4.75,
            'payment_method' => 'stripe_escrow',
            'status' => 'pending',
        ]);

        $balance = CreatorBalance::create([
            'creator_id' => $creator->id,
            'available_balance' => 0,
            'pending_balance' => 0,
            'total_earned' => 0,
            'total_withdrawn' => 0,
        ]);

        $this->assertSame(0.00, (float) $balance->available_balance);

        $service = new ContractPaymentService(
            Mockery::mock(StripeWrapper::class),
            Mockery::mock(StripeCustomerService::class)
        );

        $service->releasePaymentToCreator($contract->fresh());

        $balance->refresh();
        $contract->refresh();

        $this->assertSame('payment_available', $contract->workflow_status);
        $this->assertSame(4.75, (float) $balance->available_balance);
        $this->assertSame(4.75, (float) $balance->total_earned);

        $this->assertDatabaseHas('job_payments', [
            'contract_id' => $contract->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $creator->id,
            'type' => 'payment_available',
        ]);
    }

    public function testStudentFundsAreHeldInEscrowAndReleasedOnlyAfterContractCompletion(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
        ]);
        $brand = User::factory()->create(['role' => 'brand']);

        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'creator_id' => $student->id,
            'status' => 'active',
            'workflow_status' => 'active',
            'budget' => 99.99,
            'platform_fee' => 5.00,
            'creator_amount' => 94.99,
        ]);

        JobPayment::create([
            'contract_id' => $contract->id,
            'brand_id' => $brand->id,
            'creator_id' => $student->id,
            'total_amount' => 99.99,
            'platform_fee' => 5.00,
            'creator_amount' => 94.99,
            'payment_method' => 'stripe_escrow',
            'status' => 'pending',
        ]);

        CreatorBalance::create([
            'creator_id' => $student->id,
            'available_balance' => 0,
            'pending_balance' => 0,
            'total_earned' => 0,
            'total_withdrawn' => 0,
        ]);

        $balanceBefore = CreatorBalance::where('creator_id', $student->id)->firstOrFail();
        $this->assertSame(0.00, (float) $balanceBefore->available_balance);

        $this->assertDatabaseHas('job_payments', [
            'contract_id' => $contract->id,
            'status' => 'pending',
            'total_amount' => 99.99,
            'platform_fee' => 5.00,
            'creator_amount' => 94.99,
        ]);
        $this->assertSame(99.99, round(5.00 + 94.99, 2));

        $response = $this->actingAs($brand)->postJson('/api/campaign-timeline/complete-contract', [
            'contract_id' => $contract->id,
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
        ;

        $contract->refresh();
        $balanceAfter = CreatorBalance::where('creator_id', $student->id)->firstOrFail();

        $this->assertSame('completed', $contract->status);
        $this->assertSame('payment_available', $contract->workflow_status);
        $this->assertSame(5.00, (float) $contract->platform_fee);
        $this->assertSame(94.99, (float) $contract->creator_amount);

        $this->assertSame(94.99, (float) $balanceAfter->available_balance);
        $this->assertSame(94.99, (float) $balanceAfter->total_earned);
        $this->assertSame(0.00, (float) $balanceAfter->total_withdrawn);

        $this->assertDatabaseHas('job_payments', [
            'contract_id' => $contract->id,
            'status' => 'completed',
            'total_amount' => 99.99,
            'platform_fee' => 5.00,
            'creator_amount' => 94.99,
        ]);
    }

    public function testStudentCanRequestWithdrawalAndFundsAreReserved(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
            'stripe_account_id' => 'acct_student_test',
        ]);

        CreatorBalance::create([
            'creator_id' => $student->id,
            'available_balance' => 100.00,
            'pending_balance' => 0,
            'total_earned' => 100.00,
            'total_withdrawn' => 0,
        ]);

        DB::table('withdrawal_methods')->insert([
            'code' => 'stripe_connect_bank_account',
            'name' => 'Stripe Connect Bank Account',
            'description' => 'Stripe account payout',
            'min_amount' => 10.00,
            'max_amount' => 1000.00,
            'processing_time' => '1-2 days',
            'fee' => 0,
            'is_active' => true,
            'required_fields' => json_encode([]),
            'field_config' => json_encode([]),
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stripe = Mockery::mock(StripeWrapper::class);
        $stripe->shouldReceive('setApiKey')->andReturnNull();
        $stripe->shouldReceive('retrieveAccount')
            ->with('acct_student_test')
            ->andReturn(Account::constructFrom([
                'id' => 'acct_student_test',
                'payouts_enabled' => true,
                'charges_enabled' => true,
                'details_submitted' => true,
            ]))
        ;

        $this->app->instance(StripeWrapper::class, $stripe);

        $response = $this->actingAs($student)->postJson('/api/freelancer/withdrawals', [
            'amount' => 40.00,
            'withdrawal_method' => 'stripe_connect_bank_account',
            'withdrawal_details' => [],
        ]);

        $response
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
            ])
        ;

        $withdrawalId = $response->json('data.id');
        $this->assertNotNull($withdrawalId);

        $balance = CreatorBalance::where('creator_id', $student->id)->firstOrFail();
        $this->assertSame(60.00, (float) $balance->available_balance);
        $this->assertSame(0.00, (float) $balance->total_withdrawn);

        $this->assertDatabaseHas('withdrawals', [
            'id' => $withdrawalId,
            'creator_id' => $student->id,
            'amount' => 40.00,
            'status' => 'pending',
        ]);
    }
}

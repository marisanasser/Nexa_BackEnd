<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Contract\Contract;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * @internal
 *
 * @covers \App\Models\Contract\Contract
 */
class ContractModelAccessorsTest extends TestCase
{
    public function testProgressPercentageAccessorAlwaysReturnsInt(): void
    {
        $contract = new Contract();
        $contract->setAttribute('status', 'active');
        $contract->setAttribute('started_at', Carbon::now()->subDays(2));
        $contract->setAttribute('expected_completion_at', Carbon::now()->addDays(2));

        $progress = $contract->getProgressPercentageAttribute();

        $this->assertIsInt($progress);
        $this->assertGreaterThanOrEqual(0, $progress);
        $this->assertLessThanOrEqual(100, $progress);
    }

    public function testNullExpectedCompletionDateDoesNotBreakAccessors(): void
    {
        $contract = new Contract();
        $contract->setAttribute('status', 'active');
        $contract->setAttribute('started_at', null);
        $contract->setAttribute('expected_completion_at', null);

        $this->assertSame(0, $contract->getDaysUntilCompletionAttribute());
        $this->assertFalse($contract->isOverdue());
    }
}

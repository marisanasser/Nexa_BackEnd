<?php

declare(strict_types=1);

namespace App\Domain\Contract\DTOs;

use App\Models\Contract\Contract;

/**
 * ContractCompletionResult is a Data Transfer Object that encapsulates
 * the result of a contract completion operation.
 */
final class ContractCompletionResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $errorMessage,
        public readonly ?Contract $contract,
        public readonly float $fundsReleased
    ) {}

    /**
     * Create a successful result.
     */
    public static function success(Contract $contract, float $fundsReleased = 0.0): self
    {
        return new self(
            success: true,
            errorMessage: null,
            contract: $contract,
            fundsReleased: $fundsReleased
        );
    }

    /**
     * Create a failure result.
     */
    public static function failure(string $errorMessage): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            contract: null,
            fundsReleased: 0.0
        );
    }

    /**
     * Check if the result is successful.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the result is a failure.
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }
}

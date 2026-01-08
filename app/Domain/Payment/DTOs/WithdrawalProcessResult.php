<?php

declare(strict_types=1);

namespace App\Domain\Payment\DTOs;

use App\Models\Payment\Transaction;
use App\Models\Payment\Withdrawal;

/**
 * WithdrawalProcessResult is a Data Transfer Object that encapsulates
 * the result of a withdrawal processing operation.
 *
 * This DTO provides a clean, type-safe way to return processing results
 * without using exceptions for flow control.
 */
final class WithdrawalProcessResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $errorMessage,
        public readonly ?Withdrawal $withdrawal,
        public readonly ?Transaction $transaction
    ) {}

    /**
     * Create a successful result.
     */
    public static function success(Withdrawal $withdrawal, Transaction $transaction): self
    {
        return new self(
            success: true,
            errorMessage: null,
            withdrawal: $withdrawal,
            transaction: $transaction
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
            withdrawal: null,
            transaction: null
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

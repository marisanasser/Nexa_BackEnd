<?php

declare(strict_types=1);

namespace App\Domain\Payment\DTOs;

use App\Models\Payment\WithdrawalMethod;

/**
 * DTO for creator withdrawal requests.
 *
 * Stripe-only payload:
 * - amount
 * - withdrawal_method (stripe_connect | stripe_connect_bank_account | stripe_card)
 * - optional withdrawal_details
 */
final class WithdrawalRequestDTO
{
    public function __construct(
        public readonly int $creatorId,
        public readonly float $amount,
        public readonly string $withdrawalMethod,
        public readonly array $withdrawalDetails = []
    ) {}

    /**
     * Build DTO from HTTP request payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromRequest(array $data, int $creatorId): self
    {
        return new self(
            creatorId: $creatorId,
            amount: (float) ($data['amount'] ?? 0),
            withdrawalMethod: (string) ($data['withdrawal_method'] ?? ''),
            withdrawalDetails: is_array($data['withdrawal_details'] ?? null)
                ? $data['withdrawal_details']
                : []
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'creator_id' => $this->creatorId,
            'amount' => $this->amount,
            'withdrawal_method' => $this->withdrawalMethod,
            'withdrawal_details' => array_filter($this->withdrawalDetails),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->amount <= 0) {
            $errors[] = 'O valor do saque deve ser maior que zero.';
        }

        if ($this->amount < 20.00) {
            $errors[] = 'O valor minimo para saque e R$ 20,00.';
        }

        if ('' === trim($this->withdrawalMethod)) {
            $errors[] = 'O metodo de saque e obrigatorio.';
        } elseif (!WithdrawalMethod::isAllowedCreatorMethodCode($this->withdrawalMethod)) {
            $errors[] = 'Metodo de saque invalido. Use apenas metodos Stripe.';
        }

        return $errors;
    }

    public function isValid(): bool
    {
        return [] === $this->validate();
    }
}

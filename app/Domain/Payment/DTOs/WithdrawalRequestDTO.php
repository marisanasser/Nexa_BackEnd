<?php

declare(strict_types=1);

namespace App\Domain\Payment\DTOs;

/**
 * WithdrawalRequestDTO encapsulates all data needed to create a withdrawal request.
 *
 * This DTO ensures type safety and validation at the boundary of the domain,
 * providing a clear contract for withdrawal creation.
 */
final class WithdrawalRequestDTO
{
    public function __construct(
        public readonly int $creatorId,
        public readonly float $amount,
        public readonly string $withdrawalMethod,
        public readonly ?array $withdrawalDetails = null,
        public readonly ?string $pixKey = null,
        public readonly ?string $pixKeyType = null,
        public readonly ?string $bankCode = null,
        public readonly ?string $bankAgency = null,
        public readonly ?string $bankAccount = null,
        public readonly ?string $accountType = null,
        public readonly ?string $recipientName = null,
        public readonly ?string $recipientDocument = null
    ) {}

    /**
     * Create DTO from request array (typically from HTTP request).
     *
     * @param array $data      Request data
     * @param int   $creatorId The creator's user ID
     */
    public static function fromRequest(array $data, int $creatorId): self
    {
        return new self(
            creatorId: $creatorId,
            amount: (float) ($data['amount'] ?? 0),
            withdrawalMethod: $data['withdrawal_method'] ?? 'pix',
            withdrawalDetails: $data['withdrawal_details'] ?? null,
            pixKey: $data['pix_key'] ?? null,
            pixKeyType: $data['pix_key_type'] ?? null,
            bankCode: $data['bank_code'] ?? null,
            bankAgency: $data['bank_agency'] ?? null,
            bankAccount: $data['bank_account'] ?? null,
            accountType: $data['account_type'] ?? null,
            recipientName: $data['recipient_name'] ?? null,
            recipientDocument: $data['recipient_document'] ?? null
        );
    }

    /**
     * Convert to array format for model creation.
     */
    public function toArray(): array
    {
        $data = [
            'creator_id' => $this->creatorId,
            'amount' => $this->amount,
            'withdrawal_method' => $this->withdrawalMethod,
        ];

        // Build withdrawal details based on method
        $details = $this->withdrawalDetails ?? [];

        if ('pix' === $this->withdrawalMethod) {
            $details['pix_key'] = $this->pixKey;
            $details['pix_key_type'] = $this->pixKeyType;
        } elseif ('bank_transfer' === $this->withdrawalMethod) {
            $details['bank_code'] = $this->bankCode;
            $details['bank_agency'] = $this->bankAgency;
            $details['bank_account'] = $this->bankAccount;
            $details['account_type'] = $this->accountType;
            $details['recipient_name'] = $this->recipientName;
            $details['recipient_document'] = $this->recipientDocument;
        }

        $data['withdrawal_details'] = array_filter($details);

        return $data;
    }

    /**
     * Validate the withdrawal request.
     *
     * @return array List of validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->amount <= 0) {
            $errors[] = 'O valor do saque deve ser maior que zero.';
        }

        if ($this->amount < 20.00) {
            $errors[] = 'O valor mínimo para saque é R$ 20,00.';
        }

        if (empty($this->withdrawalMethod)) {
            $errors[] = 'O método de saque é obrigatório.';
        }

        if ('pix' === $this->withdrawalMethod && empty($this->pixKey)) {
            $errors[] = 'A chave PIX é obrigatória para saques via PIX.';
        }

        if ('bank_transfer' === $this->withdrawalMethod) {
            if (empty($this->bankCode)) {
                $errors[] = 'O código do banco é obrigatório para transferência bancária.';
            }
            if (empty($this->bankAgency)) {
                $errors[] = 'A agência é obrigatória para transferência bancária.';
            }
            if (empty($this->bankAccount)) {
                $errors[] = 'O número da conta é obrigatório para transferência bancária.';
            }
        }

        return $errors;
    }

    /**
     * Check if the request is valid.
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }
}

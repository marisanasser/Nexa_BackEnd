<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Models\Payment\CreatorBalance;
use App\Models\Payment\WithdrawalMethod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreWithdrawalRequest validates withdrawal creation requests.
 *
 * This Form Request encapsulates all validation logic for withdrawals,
 * keeping the controller clean and focused on orchestration.
 */
class StoreWithdrawalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Only creators and students can request withdrawals
        return $user->isCreator() || $user->isStudent();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'withdrawal_method' => ['required', 'string'],
            'withdrawal_details' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'O valor do saque é obrigatório.',
            'amount.numeric' => 'O valor do saque deve ser numérico.',
            'amount.min' => 'O valor mínimo para saque é R$ 0,01.',
            'withdrawal_method.required' => 'O método de saque é obrigatório.',
            'withdrawal_method.string' => 'O método de saque deve ser uma string válida.',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param mixed $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $this->validateBalance($validator);
            $this->validateWithdrawalMethod($validator);
            $this->validatePendingWithdrawals($validator);
        });
    }

    /**
     * Get the validated withdrawal method.
     */
    public function getWithdrawalMethod(): ?WithdrawalMethod
    {
        $code = $this->string('withdrawal_method')->toString();

        return WithdrawalMethod::findByCode($code);
    }

    /**
     * Get the dynamic method if applicable.
     */
    public function getDynamicMethod(): ?array
    {
        $code = $this->string('withdrawal_method')->toString();
        $withdrawalMethod = WithdrawalMethod::findByCode($code);

        if ($withdrawalMethod) {
            return null;
        }

        $user = $this->user();
        $availableMethods = $user->getWithdrawalMethods();

        return $availableMethods->firstWhere('id', $code);
    }

    /**
     * Validate that the user has sufficient balance.
     *
     * @param mixed $validator
     */
    private function validateBalance($validator): void
    {
        $user = $this->user();
        $amount = (float) $this->input('amount', 0);

        $balance = CreatorBalance::where('creator_id', $user->id)->first();

        if (!$balance || !$balance->canWithdraw($amount)) {
            $availableBalance = $balance ? $balance->formattedAvailableBalance() : 'R$ 0,00';
            $validator->errors()->add(
                'amount',
                "Saldo insuficiente para o saque. Saldo disponível: {$availableBalance}"
            );
        }
    }

    /**
     * Validate that the withdrawal method is valid.
     *
     * @param mixed $validator
     */
    private function validateWithdrawalMethod($validator): void
    {
        $user = $this->user();
        $withdrawalMethodCode = $this->string('withdrawal_method')->toString();

        $withdrawalMethod = WithdrawalMethod::findByCode($withdrawalMethodCode);

        if (!$withdrawalMethod) {
            // Check dynamic methods
            $availableMethods = $user->getWithdrawalMethods();
            $dynamicMethod = $availableMethods->firstWhere('id', $withdrawalMethodCode);

            if (!$dynamicMethod) {
                $validator->errors()->add(
                    'withdrawal_method',
                    'Método de saque inválido ou não disponível.'
                );

                return;
            }
        }

        // Check if Stripe methods require Stripe account
        if (str_contains($withdrawalMethodCode, 'stripe') && !$user->stripe_account_id) {
            $validator->errors()->add(
                'withdrawal_method',
                'Você precisa configurar sua conta Stripe antes de usar este método de saque.'
            );
        }
    }

    /**
     * Validate that the user doesn't have too many pending withdrawals.
     *
     * @param mixed $validator
     */
    private function validatePendingWithdrawals($validator): void
    {
        $user = $this->user();

        $pendingWithdrawals = $user->withdrawals()
            ->whereIn('status', ['pending', 'processing'])
            ->count()
        ;

        if ($pendingWithdrawals >= 3) {
            $validator->errors()->add(
                'amount',
                'Você tem muitos saques pendentes. Aguarde o processamento dos saques atuais.'
            );
        }
    }
}

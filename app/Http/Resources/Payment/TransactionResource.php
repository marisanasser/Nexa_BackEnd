<?php

declare(strict_types=1);

namespace App\Http\Resources\Payment;

use App\Models\Payment\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TransactionResource transforms Transaction model to API response format.
 *
 * @property Transaction $resource
 */
class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paymentData = $this->resource->payment_data ?? [];
        $pagarmeTransactionId = $paymentData['pagarme_transaction_id']
            ?? $paymentData['transaction_id']
            ?? $this->resource->stripe_payment_intent_id
            ?? $this->resource->stripe_charge_id
            ?? null;

        $contract = $this->resource->contract;
        $contractData = null;

        if ($contract) {
            $contractData = [
                'id' => $contract->id,
                'title' => $contract->title ?? 'N/A',
                'budget' => $contract->budget ?? 0,
                'creator' => $contract->creator ? [
                    'id' => $contract->creator->id,
                    'name' => $contract->creator->name,
                    'email' => $contract->creator->email,
                ] : null,
            ];
        }

        return [
            'id' => $this->resource->id,
            'pagarme_transaction_id' => $pagarmeTransactionId ?? '',
            'status' => $this->resource->status,
            'amount' => (string) $this->resource->amount,
            'payment_method' => $this->resource->payment_method ?? '',
            'card_brand' => $this->resource->card_brand ?? '',
            'card_last4' => $this->resource->card_last4 ?? '',
            'card_holder_name' => $this->resource->card_holder_name ?? '',
            'payment_data' => $paymentData,
            'paid_at' => $this->resource->paid_at?->format('Y-m-d H:i:s') ?? '',
            'expires_at' => $this->resource->expires_at?->format('Y-m-d H:i:s') ?? '',
            'created_at' => $this->resource->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->resource->updated_at->format('Y-m-d H:i:s'),
            'contract' => $this->when(null !== $contract, $contractData),
        ];
    }
}

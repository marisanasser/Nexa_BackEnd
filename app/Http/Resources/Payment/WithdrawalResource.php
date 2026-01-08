<?php

declare(strict_types=1);

namespace App\Http\Resources\Payment;

use App\Models\Payment\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WithdrawalResource transforms Withdrawal model to API response format.
 *
 * This resource provides a consistent, type-safe transformation
 * of Withdrawal data for API responses.
 *
 * @property Withdrawal $resource
 */
class WithdrawalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'amount' => $this->resource->formatted_amount,
            'raw_amount' => (float) $this->resource->amount,
            'fees' => [
                'platform_fee' => $this->resource->formatted_platform_fee_amount,
                'fixed_fee' => $this->resource->formatted_fixed_fee,
                'percentage_fee' => $this->resource->formatted_percentage_fee_amount,
                'total_fees' => $this->resource->formatted_total_fees,
            ],
            'net_amount' => $this->resource->formatted_net_amount,
            'raw_net_amount' => $this->resource->net_amount,
            'method' => $this->resource->withdrawal_method_label,
            'method_code' => $this->resource->withdrawal_method,
            'status' => $this->resource->status,
            'status_color' => $this->resource->status_color,
            'status_badge_color' => $this->resource->status_badge_color,
            'transaction_id' => $this->resource->transaction_id,
            'failure_reason' => $this->resource->failure_reason,
            'created_at' => $this->resource->created_at->format('Y-m-d H:i:s'),
            'processed_at' => $this->resource->processed_at?->format('Y-m-d H:i:s'),
            'days_since_created' => $this->resource->days_since_created,
            'is_recent' => $this->resource->is_recent,
            'can_be_cancelled' => $this->when(
                $request->routeIs('withdrawals.show'),
                $this->resource->canBeCancelled(...)
            ),
            'bank_account_info' => $this->when(
                null !== $this->resource->bank_account_info,
                $this->resource->bank_account_info
            ),
            'pix_info' => $this->when(
                null !== $this->resource->pix_info,
                $this->resource->pix_info
            ),
            'withdrawal_details' => $this->when(
                $request->routeIs('withdrawals.show'),
                $this->resource->withdrawal_details
            ),
        ];
    }

    /**
     * Get the response structure for creation.
     *
     * @return array<string, mixed>
     */
    public static function forCreation(Withdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->id,
            'amount' => $withdrawal->formatted_amount,
            'method' => $withdrawal->withdrawal_method_label,
            'status' => $withdrawal->status,
            'created_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
        ];
    }
}

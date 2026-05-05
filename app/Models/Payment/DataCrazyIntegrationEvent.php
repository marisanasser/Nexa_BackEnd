<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int                   $id
 * @property string                $external_event_id
 * @property string                $event_type
 * @property null|string           $external_id
 * @property null|string           $business_id
 * @property null|int              $user_id
 * @property null|int              $subscription_plan_id
 * @property null|string           $plan_code
 * @property null|string           $stripe_checkout_session_id
 * @property null|string           $stripe_subscription_id
 * @property string                $status
 * @property null|array            $request_payload
 * @property null|array            $response_payload
 * @property null|string           $error_message
 * @property null|Carbon           $processed_at
 * @property null|Carbon           $created_at
 * @property null|Carbon           $updated_at
 * @property null|User             $user
 * @property null|SubscriptionPlan $plan
 */
class DataCrazyIntegrationEvent extends Model
{
    use HasFactory;

    protected $table = 'datacrazy_integration_events';

    public const string STATUS_RECEIVED = 'received';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_PROCESSED = 'processed';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'external_event_id',
        'event_type',
        'external_id',
        'business_id',
        'user_id',
        'subscription_plan_id',
        'plan_code',
        'stripe_checkout_session_id',
        'stripe_subscription_id',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}

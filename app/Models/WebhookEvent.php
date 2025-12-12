<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'stripe_event_id',
        'type',
        'payload',
        'status',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}

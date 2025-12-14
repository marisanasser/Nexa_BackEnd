<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'status',
        'message',
        'campaign_id',
        'accepted_at',
        'rejected_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeBySender($query, $senderId)
    {
        return $query->where('sender_id', $senderId);
    }

    public function scopeByReceiver($query, $receiverId)
    {
        return $query->where('receiver_id', $receiverId);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function accept(): bool
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        return true;
    }

    public function reject(): bool
    {
        $this->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        return true;
    }

    public function cancel(): bool
    {
        $this->update([
            'status' => 'cancelled',
        ]);

        return true;
    }

    public function canBeAcceptedBy($user): bool
    {
        return $this->receiver_id === $user->id && $this->isPending();
    }

    public function canBeRejectedBy($user): bool
    {
        return $this->receiver_id === $user->id && $this->isPending();
    }

    public function canBeCancelledBy($user): bool
    {
        return $this->sender_id === $user->id && $this->isPending();
    }
}

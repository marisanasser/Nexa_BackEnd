<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_room_id',
        'sender_id',
        'message',
        'message_type',
        'file_path',
        'file_name',
        'file_size',
        'file_type',
        'is_read',
        'read_at',
        'offer_data',
        'is_sender',
        'is_system_message',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'offer_data' => 'array',
        'is_system_message' => 'boolean',
    ];

    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if ($this->file_path) {
            return asset('storage/'.$this->file_path);
        }

        return null;
    }

    public function getFormattedFileSizeAttribute(): ?string
    {
        if (! $this->file_size) {
            return null;
        }

        $bytes = (int) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public function isFile(): bool
    {
        return in_array($this->message_type, ['file', 'image']);
    }

    public function isImage(): bool
    {
        return $this->message_type === 'image';
    }

    public function isOffer(): bool
    {
        return $this->message_type === 'offer';
    }

    protected static function booted()
    {
        static::created(function ($message) {

            if ($message->chatRoom) {
                $message->chatRoom->updateLastMessageTimestamp();
            }

            NotificationService::notifyUserOfNewMessage($message);
        });
    }
}

<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'direct_chat_room_id',
        'sender_id',
        'message',
        'message_type',
        'file_path',
        'file_name',
        'file_size',
        'file_type',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function directChatRoom(): BelongsTo
    {
        return $this->belongsTo(DirectChatRoom::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if ($this->file_path) {
            $disk = config('filesystems.default');
            
            if ($disk === 'gcs') {
                $bucket = config('filesystems.disks.gcs.bucket');
                return "https://storage.googleapis.com/{$bucket}/{$this->file_path}";
            }
            
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

    public function markAsRead(): bool
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return true;
    }

    protected static function booted()
    {
        static::created(function ($message) {

            NotificationService::notifyUserOfNewDirectMessage($message);
        });
    }
}

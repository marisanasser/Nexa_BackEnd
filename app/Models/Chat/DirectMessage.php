<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Domain\Notification\Services\ChatNotificationService;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

use function in_array;

/**
 * @property int            $id
 * @property int            $direct_chat_room_id
 * @property int            $sender_id
 * @property null|string    $message
 * @property string         $message_type
 * @property null|string    $file_path
 * @property null|string    $file_name
 * @property null|int       $file_size
 * @property null|string    $file_type
 * @property bool           $is_read
 * @property null|Carbon    $read_at
 * @property null|Carbon    $created_at
 * @property null|Carbon    $updated_at
 * @property DirectChatRoom $directChatRoom
 * @property User           $sender
 * @property null|string    $file_url
 * @property null|string    $formatted_file_size
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
        if (!$this->file_path) {
            return null;
        }

        return \App\Helpers\FileUploadHelper::resolveUrl($this->file_path);
    }

    public function getFormattedFileSizeAttribute(): ?string
    {
        if (!$this->file_size) {
            return null;
        }

        $bytes = (int) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; ++$i) {
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
        return 'image' === $this->message_type;
    }

    public function markAsRead(): bool
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return true;
    }

    protected static function booted(): void
    {
        static::created(ChatNotificationService::notifyUserOfNewDirectMessage(...));
    }
}

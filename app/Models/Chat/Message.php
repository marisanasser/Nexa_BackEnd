<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Models\User\User;
use App\Domain\Notification\Services\ChatNotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

use function in_array;

/**
 * @method static \Illuminate\Database\Eloquent\Builder|Message create(array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Message find($id, $columns = ['*'])
 * @method static int                                           count($columns = '*')
 *
 * @property int         $id
 * @property int         $chat_room_id
 * @property int         $sender_id
 * @property string      $message
 * @property string      $message_type
 * @property null|string $file_path
 * @property null|string $file_name
 * @property null|int    $file_size
 * @property null|string $file_type
 * @property bool        $is_read
 * @property null|Carbon $read_at
 * @property null|array  $offer_data
 * @property bool        $is_sender
 * @property bool        $is_system_message
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property ChatRoom    $chatRoom
 * @property User        $sender
 * @property null|string $file_url
 * @property null|string $formatted_file_size
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
        if (!$this->file_path) {
            return null;
        }

        // Try to use GCS URL directly if configured
        $disk = config('filesystems.default');
        if ('gcs' === $disk) {
            $bucket = config('filesystems.disks.gcs.bucket', env('GOOGLE_CLOUD_STORAGE_BUCKET'));
            if ($bucket) {
                return "https://storage.googleapis.com/{$bucket}/{$this->file_path}";
            }
        }

        // Fallback to local URL with forced HTTPS
        $url = asset("storage/{$this->file_path}");

        return str_replace('http://', 'https://', $url);
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

    public function isOffer(): bool
    {
        return 'offer' === $this->message_type;
    }

    protected static function booted(): void
    {
        static::created(function ($message): void {
            if ($message->chatRoom) {
                $message->chatRoom->updateLastMessageTimestamp();
            }

            ChatNotificationService::notifyUserOfNewMessage($message);
        });
    }
}

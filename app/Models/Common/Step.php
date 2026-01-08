<?php

declare(strict_types=1);

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

use function is_array;

/**
 * Guide model for tutorial guides.
 *
 * @property null|string $video_path
 * @property null|string $video_mime
 * @property null|array  $screenshots
 * @property null|int    $created_by
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|string $video_url
 * @property array       $screenshot_urls
 */
class Step extends Model
{
    use HasFactory;

    protected $fillable = [
        'guide_id',
        'title',
        'description',
        'video_path',
        'video_mime',
        'screenshots',
        'order',
    ];

    protected $casts = [
        'screenshots' => 'array',
    ];

    protected $appends = ['video_url', 'screenshot_urls'];

    public function guide()
    {
        return $this->belongsTo(Guide::class);
    }

    public function getVideoUrlAttribute()
    {
        if (!$this->video_path) {
            return null;
        }

        return Storage::url($this->video_path);
    }

    public function getScreenshotUrlsAttribute()
    {
        if (!$this->screenshots || !is_array($this->screenshots)) {
            return [];
        }

        return array_map(Storage::url(...), $this->screenshots);
    }
}

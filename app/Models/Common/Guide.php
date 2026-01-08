<?php

declare(strict_types=1);

namespace App\Models\Common;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

use function is_array;

/**
 * Guide model for tutorial guides.
 *
 * @property int               $id
 * @property string            $title
 * @property string            $audience
 * @property null|string       $description
 * @property null|string       $video_path
 * @property null|string       $video_mime
 * @property null|array        $screenshots
 * @property null|int          $created_by
 * @property null|Carbon       $created_at
 * @property null|Carbon       $updated_at
 * @property null|string       $video_url
 * @property array             $screenshot_urls
 * @property null|User         $creator
 * @property Collection|Step[] $steps
 */
class Guide extends Model
{
    protected $fillable = [
        'title',
        'audience',
        'description',
        'video_path',
        'video_mime',
        'screenshots',
        'created_by',
    ];

    protected $casts = [
        'screenshots' => 'array',
    ];

    protected $appends = ['video_url', 'screenshot_urls'];

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

        return array_map(fn ($path) => Storage::url($path), $this->screenshots);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function steps()
    {
        return $this->hasMany(Step::class);
    }
}

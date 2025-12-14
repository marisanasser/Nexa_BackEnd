<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
        if (! $this->video_path) {
            return null;
        }

        return Storage::url($this->video_path);
    }

    public function getScreenshotUrlsAttribute()
    {
        if (! $this->screenshots || ! is_array($this->screenshots)) {
            return [];
        }

        return array_map(function ($path) {
            return Storage::url($path);
        }, $this->screenshots);
    }
}

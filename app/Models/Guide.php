<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function steps()
    {
        return $this->hasMany(\App\Models\Step::class);
    }
}

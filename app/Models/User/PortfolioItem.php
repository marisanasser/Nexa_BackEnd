<?php

declare(strict_types=1);

namespace App\Models\User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'portfolio_id',
        'file_path',
        'file_name',
        'file_type',
        'media_type',
        'file_size',
        'title',
        'description',
        'order',
    ];

    protected $appends = [
        'file_url',
        'thumbnail_url',
        'formatted_file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'order' => 'integer',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function getFileUrlAttribute(): string
    {
        if (str_starts_with($this->file_path, 'http')) {
            return $this->file_path;
        }

        $disk = config('filesystems.default');

        if ('gcs' === $disk) {
            $bucket = config('filesystems.disks.gcs.bucket');

            return "https://storage.googleapis.com/{$bucket}/{$this->file_path}";
        }

        return asset('storage/'.$this->file_path);
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; ++$i) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public function getThumbnailUrlAttribute(): string
    {
        return $this->file_url;
    }

    public function isImage(): bool
    {
        return 'image' === $this->media_type;
    }

    public function isVideo(): bool
    {
        return 'video' === $this->media_type;
    }

    public function getFileExtension(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    public function getDisplayName(): string
    {
        return $this->title ?: $this->file_name;
    }
}

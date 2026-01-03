<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'bio',
        'profile_picture',
        'project_links',
    ];

    protected $appends = [
        'profile_picture_url',
    ];

    protected $casts = [
        'project_links' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->orderBy('order');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->where('media_type', 'image')->orderBy('order');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->where('media_type', 'video')->orderBy('order');
    }

    public function getProfilePictureUrlAttribute(): ?string
    {
        // Always use the user avatar as the single source of truth to prevent sync issues
        return $this->user->avatar_url;
    }

    public function getItemsCount(): int
    {
        return $this->items()->count();
    }

    public function getImagesCount(): int
    {
        return $this->images()->count();
    }

    public function getVideosCount(): int
    {
        return $this->videos()->count();
    }

    public function hasMinimumItems(): bool
    {
        return $this->getItemsCount() >= 3;
    }

    public function isComplete(): bool
    {
        return ! empty($this->title) && ! empty($this->bio) && $this->hasMinimumItems();
    }
}

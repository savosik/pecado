<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Tags\HasTags;

class BrandStory extends Model implements HasMedia
{
    use \App\Traits\HasContentMedia;

    use \App\Traits\HasRegions;
    /** @use HasFactory<\Database\Factories\BrandFactory> */
    use HasFactory, HasTags;

    protected $fillable = [
        'title',
        'slug',
        'short_description',
        'detailed_description',
        'is_published',
        'published_at',
        'meta_title',
        'meta_description',
        'brand_id',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * Scope: только опубликованные статьи о брендах.
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}

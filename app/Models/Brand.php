<?php

namespace App\Models;

use App\Enums\BrandCategory;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Tags\HasTags;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Brand extends Model implements HasMedia
{
    use HasFactory, HasTags, InteractsWithMedia;

    protected $fillable = [
        'name',
        'external_id',
        'slug',
        'short_description',
        'category',
        'meta_title',
        'meta_description',
        'parent_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BrandCategory::class,
        ];
    }

    public function sizeCharts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(SizeChart::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Brand::class, 'parent_id');
    }

    public function story(): HasOne
    {
        return $this->hasOne(BrandStory::class);
    }

    /**
     * Get the products for this brand.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'
            ])
            ->singleFile();
    }
}

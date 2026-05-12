<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $short_description
 * @property string|null $description
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property bool $is_active
 * @property bool $show_on_home
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $featuredProducts
 * @property-read int|null $featured_products_count
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Region> $regions
 * @property-read int|null $regions_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection active()
 * @method static \Database\Factories\ProductSelectionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection forRegion(?int $regionId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection showOnHome()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereMetaDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereMetaTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereShortDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereShowOnHome($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductSelection whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProductSelection extends Model implements HasMedia
{
    use \App\Traits\HasRegions;
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'slug',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
        'is_active',
        'show_on_home',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_home' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Scope: только активные подборки.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: только подборки для главной.
     */
    public function scopeShowOnHome($query)
    {
        return $query->where('show_on_home', true);
    }

    /**
     * Scope: сортировка по sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('desktop')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
                'video/mp4', 'video/webm', 'video/quicktime',
            ])
            ->singleFile();

        $this->addMediaCollection('mobile')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
                'video/mp4', 'video/webm', 'video/quicktime',
            ])
            ->singleFile();
    }

    /**
     * Все товары подборки.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_product_selection')
            ->withPivot('featured');
    }

    /**
     * Только featured-товары (для показа на главной).
     */
    public function featuredProducts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_product_selection')
            ->withPivot('featured')
            ->wherePivot('featured', true);
    }
}

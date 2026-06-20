<?php

namespace App\Models;

use App\Enums\BrandCategory;
use App\Helpers\SearchHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;

/**
 * @property int $id
 * @property string|null $external_id
 * @property string $name
 * @property string $slug
 * @property string|null $short_description
 * @property BrandCategory $category
 * @property bool $is_featured
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $meta_keywords
 * @property int|null $parent_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Brand> $children
 * @property-read int|null $children_count
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read Brand|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @property \Illuminate\Database\Eloquent\Collection<int, \Spatie\Tags\Tag> $tags
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SizeChart> $sizeCharts
 * @property-read int|null $size_charts_count
 * @property-read \App\Models\BrandStory|null $story
 * @property-read int|null $tags_count
 *
 * @method static \Database\Factories\BrandFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereIsFeatured($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereMetaDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereMetaTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereShortDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand withAllTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand withAllTagsOfAnyType($tags)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand withAnyTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand withAnyTagsOfAnyType($tags)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand withAnyTagsOfType(array|string $type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand withoutTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 *
 * @mixin \Eloquent
 */
class Brand extends Model implements HasMedia
{
    use HasFactory, HasTags, InteractsWithMedia, Searchable;

    protected $fillable = [
        'name',
        'external_id',
        'slug',
        'short_description',
        'category',
        'is_featured',
        'meta_title',
        'meta_description',
        'meta_keywords',
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
            'is_featured' => 'boolean',
        ];
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $name = $this->name ?? '';

        return [
            'id' => (int) $this->id,
            'name' => $name,
            'name_translit' => SearchHelper::transliterate($name),
            'name_cyrillic' => SearchHelper::transliterateToCyrillic($name),
            'name_layout' => SearchHelper::convertLayout($name),
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
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
            ])
            ->singleFile();
    }
}

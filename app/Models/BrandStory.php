<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Tags\HasTags;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $short_description
 * @property string $detailed_description
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property bool $is_published
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property int|null $brand_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Brand|null $brand
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Region> $regions
 * @property-read int|null $regions_count
 * @property \Illuminate\Database\Eloquent\Collection<int, \Spatie\Tags\Tag> $tags
 * @property-read int|null $tags_count
 *
 * @method static \Database\Factories\BrandStoryFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory forRegion(?int $regionId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory published()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereBrandId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereDetailedDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereIsPublished($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereMetaDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereMetaTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereShortDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory withAllTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory withAllTagsOfAnyType($tags)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory withAnyTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory withAnyTagsOfAnyType($tags)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory withAnyTagsOfType(array|string $type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandStory withoutTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 *
 * @mixin \Eloquent
 */
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

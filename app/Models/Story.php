<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property bool $is_active
 * @property bool $is_published
 * @property bool $show_name
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Region> $regions
 * @property-read int|null $regions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StorySlide> $slides
 * @property-read int|null $slides_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story active()
 * @method static \Database\Factories\StoryFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story forRegion(?int $regionId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story published()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereIsPublished($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereShowName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Story whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Story extends Model
{
    use \App\Traits\HasRegions;
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'is_published',
        'show_name',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'show_name' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Слайды, принадлежащие этому сторису
     */
    public function slides(): HasMany
    {
        return $this->hasMany(StorySlide::class)->orderBy('sort_order');
    }

    /**
     * Скоуп для активных сторис
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Скоуп для опубликованных сторис
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * Скоуп для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}

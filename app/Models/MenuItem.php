<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $url
 * @property string|null $icon
 * @property string|null $badge_text
 * @property string|null $badge_color
 * @property string $location
 * @property string|null $footer_group
 * @property int $sort_order
 * @property bool $is_published
 * @property bool $open_in_new_tab
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<static>|MenuItem forFooter()
 * @method static Builder<static>|MenuItem forHeader()
 * @method static Builder<static>|MenuItem newModelQuery()
 * @method static Builder<static>|MenuItem newQuery()
 * @method static Builder<static>|MenuItem ordered()
 * @method static Builder<static>|MenuItem published()
 * @method static Builder<static>|MenuItem query()
 * @method static Builder<static>|MenuItem whereBadgeColor($value)
 * @method static Builder<static>|MenuItem whereBadgeText($value)
 * @method static Builder<static>|MenuItem whereCreatedAt($value)
 * @method static Builder<static>|MenuItem whereFooterGroup($value)
 * @method static Builder<static>|MenuItem whereIcon($value)
 * @method static Builder<static>|MenuItem whereId($value)
 * @method static Builder<static>|MenuItem whereIsPublished($value)
 * @method static Builder<static>|MenuItem whereLocation($value)
 * @method static Builder<static>|MenuItem whereOpenInNewTab($value)
 * @method static Builder<static>|MenuItem whereSortOrder($value)
 * @method static Builder<static>|MenuItem whereTitle($value)
 * @method static Builder<static>|MenuItem whereUpdatedAt($value)
 * @method static Builder<static>|MenuItem whereUrl($value)
 *
 * @mixin \Eloquent
 */
class MenuItem extends Model
{
    protected $fillable = [
        'title',
        'url',
        'icon',
        'badge_text',
        'badge_color',
        'location',
        'footer_group',
        'sort_order',
        'is_published',
        'open_in_new_tab',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'open_in_new_tab' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Только опубликованные пункты.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Пункты для хедера.
     */
    public function scopeForHeader(Builder $query): Builder
    {
        return $query->whereIn('location', ['header', 'both']);
    }

    /**
     * Пункты для футера.
     */
    public function scopeForFooter(Builder $query): Builder
    {
        return $query->whereIn('location', ['footer', 'both']);
    }

    /**
     * Сортировка по умолчанию.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}

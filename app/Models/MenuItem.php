<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

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

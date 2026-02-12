<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
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

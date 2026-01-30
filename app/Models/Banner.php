<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'linkable_type',
        'linkable_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Полиморфная связь - сущность, на которую ссылается баннер
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Скоуп для активных баннеров
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Скоуп для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}

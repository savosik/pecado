<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Тип дефекта — элемент справочника для быстрого выбора при заведении некондиции.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 *
 * @method static Builder<static>|DefectType active()
 * @method static Builder<static>|DefectType ordered()
 *
 * @mixin \Eloquent
 */
class DefectType extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}

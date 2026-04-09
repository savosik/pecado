<?php

namespace App\Traits;

use App\Models\Region;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasRegions
{
    /**
     * Регионы, к которым привязан контент.
     */
    public function regions(): MorphToMany
    {
        return $this->morphToMany(Region::class, 'regionable');
    }

    /**
     * Scope: фильтрация по региону пользователя.
     *
     * - Если у контента нет привязанных регионов — показывается всем.
     * - Если есть — показывается только пользователям из этих регионов.
     * - Если $regionId = null (неавторизованный) — показывается только контент без привязки.
     */
    public function scopeForRegion($query, ?int $regionId): void
    {
        $query->where(function ($q) use ($regionId) {
            // Контент без привязки к регионам — показываем всем
            $q->whereDoesntHave('regions');

            // Если есть конкретный регион — также показываем контент, привязанный к этому региону
            if ($regionId) {
                $q->orWhereHas('regions', fn ($sub) => $sub->where('regions.id', $regionId));
            }
        });
    }
}

<?php

namespace App\Services\ProductExport;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Базовый класс для полей-фильтров по relation (in / not_in).
 *
 * Поддерживает два типа:
 * - FK-колонка прямо на products (brand_id, model_id)
 * - Many-to-many через whereHas (categories, warehouses, certificates)
 *
 * Подклассу нужно указать:
 * - key(), name(), group(), searchUrl()
 * - filterMode(): 'direct' | 'relation'
 * - column() — для 'direct' (имя FK-колонки)
 * - relation(), relationKey() — для 'relation' (имя связи и PK связанной таблицы)
 */
abstract class RelationFilterField extends ExportField
{
    public function isExportable(): bool
    {
        return false; // Только для фильтрации
    }

    public function filterType(): ?string
    {
        return 'relation';
    }

    public function operators(): array
    {
        return ['in', 'not_in'];
    }

    /**
     * 'direct' — FK на products, 'relation' — many-to-many через whereHas
     */
    abstract protected function filterMode(): string;

    /**
     * Имя FK-колонки (для filterMode = 'direct')
     */
    protected function column(): string
    {
        return '';
    }

    /**
     * Имя Eloquent-связи (для filterMode = 'relation')
     */
    protected function relation(): string
    {
        return '';
    }

    /**
     * Ключ PK в связанной таблице (для filterMode = 'relation')
     */
    protected function relationKey(): string
    {
        return 'id';
    }

    public function applyFilter(Builder $query, string $operator, mixed $value): void
    {
        $ids = is_array($value) ? $value : [$value];

        if ($this->filterMode() === 'direct') {
            if ($operator === 'not_in') {
                $query->whereNotIn($this->column(), $ids);
            } else {
                $query->whereIn($this->column(), $ids);
            }
        } else {
            $rel = $this->relation();
            $key = $this->relationKey();

            if ($operator === 'not_in') {
                $query->whereDoesntHave($rel, fn ($q) => $q->whereIn($key, $ids));
            } else {
                $query->whereHas($rel, fn ($q) => $q->whereIn($key, $ids));
            }
        }
    }
}

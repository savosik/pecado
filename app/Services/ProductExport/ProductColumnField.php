<?php

namespace App\Services\ProductExport;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Базовый класс для полей, которые являются прямыми колонками таблицы products.
 *
 * Подклассу нужно определить только:
 * - key(), name(), group()
 * - column() — имя колонки
 * - columnType() — 'text' | 'numeric' | 'boolean' | 'date'
 */
abstract class ProductColumnField extends ExportField
{
    /**
     * Имя колонки в таблице products.
     */
    abstract protected function column(): string;

    /**
     * Тип колонки: text | numeric | boolean | date
     */
    abstract protected function columnType(): string;

    /**
     * Является ли поле ценовым? Переопределяется в конкретных полях.
     */
    protected function isPriceField(): bool
    {
        return false;
    }

    public function modifierType(): ?string
    {
        if ($this->columnType() === 'boolean') {
            return 'boolean';
        }
        if ($this->isPriceField()) {
            return 'price';
        }
        return null;
    }

    public function filterType(): ?string
    {
        return $this->columnType();
    }

    public function operators(): array
    {
        return match ($this->columnType()) {
            'text' => ['=', 'contains', 'not_contains', 'starts_with'],
            'numeric' => ['=', '>', '<', '>=', '<=', 'between'],
            'boolean' => ['='],
            'date' => ['>', '<', 'between'],
            default => ['='],
        };
    }

    public function applyFilter(Builder $query, string $operator, mixed $value): void
    {
        $col = $this->column();

        match ($this->columnType()) {
            'text' => $this->applyTextFilter($query, $col, $operator, $value),
            'numeric' => $this->applyNumericFilter($query, $col, $operator, $value),
            'boolean' => $query->where($col, (bool) $value),
            'date' => $this->applyNumericFilter($query, $col, $operator, $value), // same logic
            default => $query->where($col, $value),
        };
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $value = $product->{$this->column()};

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    // ─── Helpers ────────────────────────────────────

    protected function applyTextFilter(Builder $query, string $col, string $operator, mixed $value): void
    {
        match ($operator) {
            '=' => $query->where($col, $value),
            'contains' => $query->where($col, 'like', "%{$value}%"),
            'not_contains' => $query->where($col, 'not like', "%{$value}%"),
            'starts_with' => $query->where($col, 'like', "{$value}%"),
            default => null,
        };
    }

    protected function applyNumericFilter(Builder $query, string $col, string $operator, mixed $value): void
    {
        if ($operator === 'between' && is_array($value) && count($value) === 2) {
            $query->whereBetween($col, [$value[0], $value[1]]);
        } else {
            $query->where($col, $operator, $value);
        }
    }
}

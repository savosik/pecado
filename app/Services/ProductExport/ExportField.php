<?php

namespace App\Services\ProductExport;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Абстрактный базовый класс для полей экспорта/фильтрации товаров.
 *
 * Каждое поле описывает:
 * - Идентификацию (key, name, group)
 * - Возможности (filterable / exportable)
 * - Типы и операторы для фильтрации
 * - Логику применения фильтра к Builder
 * - Логику извлечения значения из Product
 */
abstract class ExportField
{
    /**
     * Уникальный ключ поля (используется в JSON-фильтрах и fields-массиве).
     * Например: 'name', 'brand.name', 'attr.5', 'discounted_price'
     */
    abstract public function key(): string;

    /**
     * Отображаемое название поля.
     * Например: 'Наименование', 'Базовая цена'
     */
    abstract public function name(): string;

    /**
     * Группа, к которой относится поле.
     * Например: 'Основные', 'Флаги', 'Бренд'
     */
    abstract public function group(): string;

    /**
     * Краткое описание поля для UI.
     */
    public function description(): string
    {
        return '';
    }

    /**
     * Тип модификатора поля: 'price', 'boolean', 'multi_value', или null.
     * Используется фронтом для отображения настроек.
     */
    public function modifierType(): ?string
    {
        return null;
    }

    /**
     * Группа поля для фильтрации (если отличается от group()).
     * По умолчанию = group().
     */
    public function filterGroup(): string
    {
        return $this->group();
    }

    /**
     * Можно ли использовать поле как фильтр?
     */
    public function isFilterable(): bool
    {
        return true;
    }

    /**
     * Можно ли использовать поле для экспорта?
     */
    public function isExportable(): bool
    {
        return true;
    }

    /**
     * Тип поля для UI фильтра: text, numeric, boolean, date, select, relation.
     * Если поле не фильтруемое, может вернуть null.
     */
    public function filterType(): ?string
    {
        return null;
    }

    /**
     * Доступные операторы фильтрации.
     */
    public function operators(): array
    {
        return [];
    }

    /**
     * Варианты значений для select-полей (массив ['value' => ..., 'label' => ...]).
     */
    public function options(): array
    {
        return [];
    }

    /**
     * URL для поиска вариантов (для relation-полей).
     */
    public function searchUrl(): ?string
    {
        return null;
    }

    /**
     * Какие Eloquent-связи нужно eager-load для этого поля.
     * Возвращает массив имён связей.
     */
    public function eagerLoad(): array
    {
        return [];
    }

    /**
     * Применить фильтр к запросу.
     */
    public function applyFilter(Builder $query, string $operator, mixed $value): void
    {
        // По умолчанию ничего не делает.
        // Переопределяется в конкретных полях.
    }

    /**
     * Получить значение поля из продукта.
     */
    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return null;
    }

    /**
     * Дополнительные мета-данные для определения фильтра (для фронтенда).
     */
    public function filterMeta(): array
    {
        return [];
    }

    /**
     * Сериализация в определение фильтра для фронтенда.
     */
    public function toFilterDefinition(): array
    {
        $def = [
            'key' => $this->key(),
            'label' => $this->name(),
            'type' => $this->filterType(),
            'operators' => $this->operators(),
        ];

        if ($options = $this->options()) {
            $def['options'] = $options;
        }

        if ($url = $this->searchUrl()) {
            $def['search_url'] = $url;
        }

        return array_merge($def, $this->filterMeta());
    }

    /**
     * Сериализация в определение экспортного поля для фронтенда.
     */
    public function toFieldDefinition(): array
    {
        $def = [
            'key' => $this->key(),
            'label' => $this->name(),
        ];

        if ($desc = $this->description()) {
            $def['description'] = $desc;
        }

        if ($mod = $this->modifierType()) {
            $def['modifier_type'] = $mod;
        }

        return $def;
    }
}

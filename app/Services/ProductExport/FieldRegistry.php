<?php

namespace App\Services\ProductExport;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Attribute;
use Illuminate\Support\Collection;

/**
 * Реестр ExportField.
 *
 * Собирает все статические поля из Fields/ + динамические атрибуты из БД.
 * Предоставляет единый API для получения полей, фильтров, eager-load и resolve(key).
 */
class FieldRegistry
{
    /** @var Collection<string, ExportField> keyed by field key() */
    protected Collection $fields;

    protected bool $booted = false;

    public function __construct(
        protected PriceServiceInterface $priceService,
        protected StockServiceInterface $stockService,
    ) {
        $this->fields = collect();
    }

    /**
     * Убедиться, что все поля зарегистрированы.
     */
    protected function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->registerStaticFields();
        $this->registerDynamicAttributes();
        $this->booted = true;
    }

    /**
     * Регистрация статических полей (из конкретных классов).
     */
    protected function registerStaticFields(): void
    {
        $fields = [
            // Основные
            new Fields\IdField(),
            new Fields\NameField(),
            new Fields\SkuField(),
            new Fields\CodeField(),
            new Fields\BarcodeField(),
            new Fields\BasePriceField(),
            new Fields\RecommendedPriceField(),
            new Fields\SlugField(),
            new Fields\UrlField(),
            new Fields\TnvedField(),
            new Fields\ExternalIdField(),
            new Fields\DescriptionField(),
            new Fields\ShortDescriptionField(),
            new Fields\MetaTitleField(),
            new Fields\MetaDescriptionField(),
            new Fields\DescriptionPlainField(),
            new Fields\ShortDescriptionPlainField(),

            // Флаги
            new Fields\IsNewField(),
            new Fields\IsBestsellerField(),
            new Fields\IsMarkedField(),
            new Fields\IsLiquidationField(),
            new Fields\ForMarketplacesField(),

            // Бренд (экспорт)
            new Fields\BrandNameField(),
            new Fields\BrandSlugField(),
            new Fields\BrandCategoryField(),

            // Модель (экспорт)
            new Fields\ModelNameField(),
            new Fields\ModelCodeField(),

            // Категории (экспорт)
            new Fields\CategoriesNameField(),
            new Fields\CategoryPathField(),

            // Сертификаты (экспорт)
            new Fields\CertificatesNameField(),

            // Размерная сетка
            new Fields\SizeChartNameField(),

            // Складские остатки
            new Fields\WarehousesNameField(),
            new Fields\WarehousesQuantityField(),
            new Fields\TotalStockField(),

            // Штрихкоды
            new Fields\BarcodesField(),

            // Медиа
            new Fields\MainImageField(),
            new Fields\AdditionalImagesField(),
            new Fields\AllImagesField(),
            new Fields\VideoField(),

            // Пользовательские (по клиенту) — с инжекцией сервисов
            new Fields\DiscountedPriceField($this->priceService),
            new Fields\DiscountPercentageField($this->priceService),
            new Fields\UserStockAvailableField($this->stockService),
            new Fields\UserStockPreorderField($this->stockService),
            new Fields\ClientRegionField(),

            // Даты
            new Fields\CreatedAtField(),
            new Fields\UpdatedAtField(),

            // Фильтры-only (связи)
            new Fields\Filters\BrandFilterField(),
            new Fields\Filters\CategoryFilterField(),
            new Fields\Filters\ModelFilterField(),
            new Fields\Filters\WarehouseFilterField(),
            new Fields\Filters\CertificateFilterField(),
        ];

        foreach ($fields as $field) {
            $this->fields->put($field->key(), $field);
        }
    }

    /**
     * Регистрация динамических атрибутов из БД.
     */
    protected function registerDynamicAttributes(): void
    {
        $attributes = Attribute::with('values', 'attributeGroup')->orderBy('name')->get();

        foreach ($attributes as $attribute) {
            $field = new DynamicAttributeField($attribute);
            // Для фильтров ключ attr.{id}, для экспорта attribute.{id}
            $this->fields->put($field->key(), $field);
        }
    }

    // ─── Public API ──────────────────────────────

    /**
     * Все зарегистрированные поля.
     */
    public function all(): Collection
    {
        $this->boot();
        return $this->fields;
    }

    /**
     * Найти поле по ключу.
     * Для атрибутов ищем и по attr.{id}, и по attribute.{id}.
     */
    public function resolve(string $key): ?ExportField
    {
        $this->boot();

        // Прямой поиск
        if ($this->fields->has($key)) {
            return $this->fields->get($key);
        }

        // attribute.{id} → attr.{id} (экспортный ключ → фильтровый ключ)
        if (str_starts_with($key, 'attribute.')) {
            $attrId = str_replace('attribute.', '', $key);
            $filterKey = "attr.{$attrId}";
            return $this->fields->get($filterKey);
        }

        // Legacy: attribute filter
        if ($key === 'attribute') {
            return null; // handled separately in service
        }

        return null;
    }

    /**
     * Доступные фильтры, сгруппированные для фронтенда.
     */
    public function getAvailableFilters(): array
    {
        $this->boot();

        $groups = [];

        $this->fields
            ->filter(fn (ExportField $f) => $f->isFilterable())
            ->each(function (ExportField $field) use (&$groups) {
                $group = $field->filterGroup();
                // Для динамических атрибутов — добавляем "Атр:" префикс
                if ($field instanceof DynamicAttributeField) {
                    $group = "Атр: {$group}";
                }

                if (!isset($groups[$group])) {
                    $groups[$group] = [];
                }
                $groups[$group][] = $field->toFilterDefinition();
            });

        return collect($groups)->map(fn ($fields, $group) => [
            'group' => $group,
            'fields' => $fields,
        ])->values()->toArray();
    }

    /**
     * Доступные поля экспорта, сгруппированные для фронтенда.
     */
    public function getAvailableFields(): array
    {
        $this->boot();

        $groups = [];

        $this->fields
            ->filter(fn (ExportField $f) => $f->isExportable())
            ->each(function (ExportField $field) use (&$groups) {
                $group = $field->group();
                // Для динамических атрибутов — группа "Атрибуты"
                if ($field instanceof DynamicAttributeField) {
                    $group = 'Атрибуты';
                }

                if (!isset($groups[$group])) {
                    $groups[$group] = [];
                }
                $groups[$group][] = $field->toFieldDefinition();
            });

        return collect($groups)->map(fn ($fields, $group) => [
            'group' => $group,
            'fields' => $fields,
        ])->values()->toArray();
    }

    /**
     * Собрать все eager-load связи для выбранных полей экспорта.
     */
    public function eagerLoadFor(array $fieldKeys): array
    {
        $this->boot();

        $relations = [];

        foreach ($fieldKeys as $key) {
            $field = $this->resolve($key);
            if ($field) {
                foreach ($field->eagerLoad() as $relation) {
                    $relations[$relation] = true;
                }
            }
        }

        return array_keys($relations);
    }

    /**
     * Получить label'ы для выбранных полей.
     */
    public function getFieldLabels(array $fieldKeys): array
    {
        $this->boot();

        $labels = [];
        foreach ($fieldKeys as $key) {
            $field = $this->resolve($key);
            $labels[$key] = $field?->name() ?? $key;
        }

        return $labels;
    }
}

<?php

namespace App\Services\ProductExport;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Attribute;
use App\Models\Warehouse;
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
        $this->registerDynamicWarehouses();
        $this->booted = true;
    }

    /**
     * Регистрация статических полей (из конкретных классов).
     */
    protected function registerStaticFields(): void
    {
        $fields = [
            // Основные
            new Fields\IdField,
            new Fields\NameField,
            new Fields\SkuField,
            new Fields\CodeField,
            new Fields\BarcodeField,
            new Fields\BasePriceField,
            new Fields\RecommendedPriceField,
            new Fields\SlugField,
            new Fields\UrlField,
            new Fields\TnvedField,
            new Fields\ExternalIdField,
            new Fields\DescriptionField,
            new Fields\ShortDescriptionField,
            new Fields\MetaTitleField,
            new Fields\MetaDescriptionField,
            new Fields\DescriptionPlainField,
            new Fields\ShortDescriptionPlainField,

            // Флаги
            new Fields\IsNewField,
            new Fields\IsBestsellerField,
            new Fields\IsMarkedField,
            new Fields\IsLiquidationField,
            new Fields\ForMarketplacesField,

            // Бренд (экспорт)
            new Fields\BrandNameField,
            new Fields\BrandSlugField,
            new Fields\BrandCategoryField,
            new Fields\BrandExternalIdField,

            // Модель (экспорт)
            new Fields\ModelNameField,
            new Fields\ModelCodeField,
            new Fields\ModelExternalIdField,

            // Категории (экспорт)
            new Fields\CategoriesNameField,
            new Fields\CategoryPathField,
            new Fields\CategoryIdField,
            new Fields\CategoryExternalIdField,

            // Сертификаты (экспорт)
            new Fields\CertificatesNameField,

            // Размерная сетка
            new Fields\SizeChartNameField,

            // Складские остатки
            new Fields\WarehousesNameField,
            new Fields\WarehousesQuantityField,
            new Fields\TotalStockField,

            // Габариты и вес
            new Fields\WeightGrossField,
            new Fields\WeightNetField,
            new Fields\WidthField,
            new Fields\HeightField,
            new Fields\DepthField,
            new Fields\HsCodeField,

            // Штрихкоды
            new Fields\BarcodesField,

            // Медиа
            new Fields\MainImageField,
            new Fields\AdditionalImagesField,
            new Fields\AllImagesField,
            new Fields\VideoField,
            // Отдельные колонки на каждое изображение по порядку (image.0 — main,
            // image.1 / image.2 / image.3 / image.4 — следующие из additional).
            // Партнёры часто ждут эти колонки отдельно (image, image1, image2…).
            new Fields\ImageByPositionField(0),
            new Fields\ImageByPositionField(1),
            new Fields\ImageByPositionField(2),
            new Fields\ImageByPositionField(3),
            new Fields\ImageByPositionField(4),

            // Пользовательские (по клиенту) — с инжекцией сервисов
            new Fields\DiscountedPriceField($this->priceService),
            new Fields\DiscountPercentageField($this->priceService),
            new Fields\UserStockAvailableField($this->stockService),
            new Fields\UserStockPreorderField($this->stockService),
            new Fields\ClientRegionField,

            // Свод всех атрибутов в одной колонке (sex-opt-совместимый формат)
            new Fields\AllAttributesField,

            // Даты
            new Fields\CreatedAtField,
            new Fields\UpdatedAtField,

            // Фильтры-only (связи)
            new Fields\Filters\BrandFilterField,
            new Fields\Filters\CategoryFilterField,
            new Fields\Filters\ModelFilterField,
            new Fields\Filters\WarehouseFilterField,
            new Fields\Filters\CertificateFilterField,
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
        $attributes = Attribute::active()->with('values', 'attributeGroup')->orderBy('name')->get();

        foreach ($attributes as $attribute) {
            $field = new DynamicAttributeField($attribute);
            // Для фильтров ключ attr.{id}, для экспорта attribute.{id}
            $this->fields->put($field->key(), $field);
        }
    }

    /**
     * Регистрация динамических полей «Остаток по складу X» из БД.
     */
    protected function registerDynamicWarehouses(): void
    {
        $warehouses = Warehouse::query()->orderBy('name')->get();

        foreach ($warehouses as $warehouse) {
            $field = new Fields\WarehouseQuantityField($warehouse, $this->stockService);
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
     * Для атрибутов ищем по attr.{id}, attribute.{slug} и legacy attribute.{id}.
     */
    public function resolve(string $key): ?ExportField
    {
        $this->boot();

        // Алиас для дублей: ключ `category_path#alt` резолвится в то же поле,
        // что и `category_path`, но даёт отдельную колонку в выгрузке. Why:
        // некоторые партнёры пишут одно и то же значение в две разные колонки
        // (например, sex-opt отдаёт category_title и category_new_title как
        // одинаковые строки) — без алиаса второй ключ перезаписал бы первый
        // в массиве $row.
        if (($hashPos = strpos($key, '#')) !== false) {
            $key = substr($key, 0, $hashPos);
        }

        // Прямой поиск (статические поля + attr.{id} + warehouse.{id}.quantity)
        if ($this->fields->has($key)) {
            return $this->fields->get($key);
        }

        // attribute.{slug} или legacy attribute.{id}
        if (str_starts_with($key, 'attribute.')) {
            $identifier = str_replace('attribute.', '', $key);

            // Если идентификатор числовой — legacy формат attribute.{id}
            if (is_numeric($identifier)) {
                $filterKey = "attr.{$identifier}";

                return $this->fields->get($filterKey);
            }

            // Иначе — ищем по slug
            return $this->fields->first(function (ExportField $field) use ($identifier) {
                return $field instanceof DynamicAttributeField
                    && $field->getAttributeSlug() === $identifier;
            });
        }

        // image.{N} — виртуальное поле изображения по позиции (0 — главное)
        if (preg_match('/^image\.(\d+)$/', $key, $m)) {
            return new Fields\ImageByPositionField((int) $m[1]);
        }

        // placeholder.{slug} — виртуальная пустая колонка
        if (str_starts_with($key, 'placeholder.')) {
            $slug = substr($key, strlen('placeholder.'));

            return new Fields\EmptyPlaceholderField($slug);
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

                if (! isset($groups[$group])) {
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

                if (! isset($groups[$group])) {
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

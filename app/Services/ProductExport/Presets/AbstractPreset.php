<?php

namespace App\Services\ProductExport\Presets;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Базовый класс для всех пресетов.
 * Содержит общую логику загрузки товаров со всеми связями, атрибутами,
 * ценами и остатками пользователя.
 *
 * Использует chunk-подход для обработки больших каталогов (~10k+ товаров)
 * без переполнения памяти.
 */
abstract class AbstractPreset implements PresetInterface
{
    protected const CHUNK_SIZE = 500;

    public function __construct(
        protected PriceServiceInterface $priceService,
        protected StockServiceInterface $stockService,
    ) {}

    /**
     * Получить пользователя из экспорта.
     */
    protected function resolveClientUser(ProductExport $export): ?User
    {
        return $export->client_user_id
            ? User::with('region')->find($export->client_user_id)
            : null;
    }

    /**
     * Обработка товаров чанками через callback.
     * Вызывает $callback для каждого чанка mapped-данных.
     *
     * @param  callable(Collection<int, array>): void  $callback  получает коллекцию rich-data массивов
     */
    protected function eachChunk(ProductExport $export, callable $callback): void
    {
        $clientUser = $this->resolveClientUser($export);

        $this->buildBaseQuery()
            ->chunk(static::CHUNK_SIZE, function (Collection $products) use ($clientUser, $callback) {
                $mapped = $products->map(fn (Product $product) => $this->mapProduct($product, $clientUser));
                $callback($mapped);
            });
    }

    /**
     * Собрать полный набор данных для экспорта (малые каталоги, XML).
     * Для больших каталогов лучше использовать eachChunk().
     */
    protected function fetchRichData(ProductExport $export): Collection
    {
        $clientUser = $this->resolveClientUser($export);

        $result = collect();

        $this->buildBaseQuery()
            ->chunk(static::CHUNK_SIZE, function (Collection $products) use ($clientUser, &$result) {
                $mapped = $products->map(fn (Product $product) => $this->mapProduct($product, $clientUser));
                $result = $result->concat($mapped);
            });

        return $result;
    }

    /**
     * Базовый запрос: все товары со всеми нужными связями.
     */
    protected function buildBaseQuery(): Builder
    {
        return Product::query()
            ->with([
                'brand',
                'category.ancestors',
                'media',
                'attributeValues.attribute',
                'attributeValues.attributeValue',
                'warehouses',
                'barcodes',
                'model',
            ]);
    }

    /**
     * Парсинг товара в универсальный массив Rich Data.
     */
    protected function mapProduct(Product $product, ?User $clientUser): array
    {
        // Цена с учетом скидок клиента
        $price = $product->base_price;
        if ($clientUser) {
            try {
                $priceResult = $this->priceService->getPriceResult($product, $clientUser);
                $price = round($priceResult->getDisplayPrice(), 2);
            } catch (\Throwable) {
                // fallback to base_price
            }
        }

        // Остатки по региону клиента
        $stockAvailable = 0;
        if ($clientUser) {
            try {
                $stockAvailable = $this->stockService->getAvailableStock($product, $clientUser);
            } catch (\Throwable) {
                // fallback to 0
            }
        }

        // Категория
        $category = $product->category;
        $categoryPath = null;
        $categoryId = null;
        if ($category) {
            $ancestors = $category->ancestors->pluck('name')->toArray();
            $ancestors[] = $category->name;
            $categoryPath = implode(' > ', $ancestors);
            $categoryId = $category->id;
        }

        // Изображения
        $mainImage = $product->getFirstMedia('main')?->getFullUrl();
        $additionalImages = $product->getMedia('additional')
            ->map(fn ($m) => $m->getFullUrl())
            ->toArray();

        // Все атрибуты
        $attributes = [];
        foreach ($product->attributeValues as $av) {
            $attr = $av->attribute;
            if ($attr && (! $attr->is_active || ! $attr->show_in_export)) {
                continue;
            }

            $attrName = $attr?->name ?? "attr_{$av->attribute_id}";
            $attrUnit = $attr?->unit;

            $value = null;
            if ($attr?->isBoolean()) {
                $value = $av->boolean_value !== null ? ($av->boolean_value ? 'Да' : 'Нет') : null;
            } elseif ($attr?->isNumber()) {
                $value = $av->number_value;
            } elseif ($av->attribute_value_id) {
                $value = $av->attributeValue?->value ?? '';
            } elseif ($av->text_value !== null && $av->text_value !== '') {
                $value = $av->text_value;
            } elseif ($av->number_value !== null) {
                $value = $av->number_value;
            }

            if ($value !== null) {
                $attributes[] = [
                    'name' => $attrName,
                    'value' => (string) $value,
                    'unit' => $attrUnit,
                ];
            }
        }

        // Штрихкоды
        $barcodes = $product->barcodes->pluck('barcode')->toArray();

        // Описание — приоритет: description_html > description > short_description
        $description = $product->description_html ?: $product->description ?: $product->short_description;
        $descriptionPlain = strip_tags($description ?? '');

        return [
            'id' => $product->id,
            'external_id' => $product->external_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'code' => $product->code,
            'slug' => $product->slug,
            'barcode' => $product->barcode,
            'barcodes' => $barcodes,
            'base_price' => (float) $product->base_price,
            'price' => (float) $price,
            'stock' => $stockAvailable,
            'brand_name' => $product->brand?->name,
            'brand_slug' => $product->brand?->slug,
            'category_id' => $categoryId,
            'category_name' => $category?->name,
            'category_path' => $categoryPath,
            'description' => $description,
            'description_plain' => $descriptionPlain,
            'short_description' => $product->short_description,
            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'main_image' => $mainImage,
            'additional_images' => $additionalImages,
            'attributes' => $attributes,
            'is_new' => $product->is_new,
            'is_bestseller' => $product->is_bestseller,
            'weight' => null,
            'model_name' => $product->model?->name,
            'model_code' => $product->model?->code,
            'url' => $product->url ?: url("/products/{$product->slug}"),
        ];
    }

    /**
     * Все категории (для формирования дерева в YML / Google Merchant).
     */
    protected function fetchCategories(): Collection
    {
        return Category::where('is_active', true)
            ->with('ancestors')
            ->get();
    }

    /**
     * Все атрибуты из БД (для маппинга заголовков).
     */
    protected function fetchAllAttributes(): Collection
    {
        return Attribute::active()
            ->where('show_in_export', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Генерировать имя файла для скачивания.
     */
    protected function generateFilename(ProductExport $export): string
    {
        $date = now()->format('Y-m-d');

        return "export_{$this->key()}_{$date}.{$this->fileExtension()}";
    }

    /**
     * Стандартная реализация generate() через StreamedResponse.
     */
    public function generate(ProductExport $export): StreamedResponse
    {
        $filename = $this->generateFilename($export);

        return new StreamedResponse(function () use ($export) {
            $stream = fopen('php://output', 'w');
            $this->writeToStream($stream, $export);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $this->mimeType(),
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

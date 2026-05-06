<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * Полный каталог в формате JSON.
 *
 * Содержит дерево категорий с вложенными родителями и полные данные товаров
 * (атрибуты, изображения, цены, остатки, бренды, штрихкоды).
 *
 * Подходит для: любых CMS и сервисов, поддерживающих JSON-импорт,
 * собственных интеграций, мобильных приложений и API-потребителей.
 *
 * Использует chunk-подход для обработки больших каталогов.
 */
class JsonCatalogPreset extends AbstractPreset
{
    protected function eagerLoad(): array
    {
        return [...parent::eagerLoad(), 'barcodes', 'model'];
    }

    public function key(): string
    {
        return 'json_catalog';
    }

    public function name(): string
    {
        return 'Полный каталог (JSON)';
    }

    public function description(): string
    {
        return 'JSON-файл с полным каталогом товаров, деревом категорий, атрибутами, изображениями и ценами. Универсальный формат для любых интеграций.';
    }

    public function fileExtension(): string
    {
        return 'json';
    }

    public function mimeType(): string
    {
        return 'application/json; charset=utf-8';
    }

    public function color(): string
    {
        return 'purple';
    }

    public function icon(): string
    {
        return 'LuBraces';
    }

    public function writeToStream($stream, ProductExport $export): void
    {
        // Собираем дерево категорий
        $categories = $this->fetchCategories();
        $categoryTree = $this->buildCategoryTree($categories);

        // Начало JSON: метаданные + категории
        fwrite($stream, "{\n");
        fwrite($stream, '  "generated_at": '.json_encode(now()->toISOString(), JSON_UNESCAPED_UNICODE).",\n");
        fwrite($stream, '  "shop": '.json_encode(config('app.name', 'Pecado'), JSON_UNESCAPED_UNICODE).",\n");
        fwrite($stream, '  "url": '.json_encode(config('app.url'), JSON_UNESCAPED_UNICODE).",\n");
        fwrite($stream, '  "categories": '.json_encode($categoryTree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).",\n");
        fwrite($stream, "  \"products\": [\n");

        // Товары чанками
        $isFirst = true;
        $this->eachChunk($export, function ($items) use ($stream, &$isFirst) {
            foreach ($items as $item) {
                if (! $isFirst) {
                    fwrite($stream, ",\n");
                }
                $isFirst = false;

                $product = $this->formatProduct($item);
                fwrite($stream, '    '.json_encode($product, JSON_UNESCAPED_UNICODE));
            }
        });

        fwrite($stream, "\n  ]\n");
        fwrite($stream, "}\n");
    }

    /**
     * Форматирование одного товара для JSON.
     */
    protected function formatProduct(array $item): array
    {
        $product = [
            'id' => $item['id'],
            'external_id' => $item['external_id'],
            'name' => $item['name'],
            'slug' => $item['slug'],
            'url' => $item['url'],
            'sku' => $item['sku'],
            'code' => $item['code'],
            'barcode' => $item['barcode'],
            'barcodes' => $item['barcodes'],
            'price' => $item['price'],
            'base_price' => $item['base_price'],
            'stock' => $item['stock'],
            'available' => $item['stock'] > 0,
            'brand' => $item['brand_name'] ? [
                'name' => $item['brand_name'],
                'slug' => $item['brand_slug'],
            ] : null,
            'category' => $item['category_id'] ? [
                'id' => $item['category_id'],
                'name' => $item['category_name'],
                'path' => $item['category_path'],
            ] : null,
            'model' => $item['model_name'] ? [
                'name' => $item['model_name'],
                'code' => $item['model_code'],
            ] : null,
            'description' => $item['description'],
            'short_description' => $item['short_description'],
            'meta_title' => $item['meta_title'],
            'meta_description' => $item['meta_description'],
            'images' => [
                'main' => $item['main_image'],
                'additional' => $item['additional_images'],
            ],
            'attributes' => array_map(fn ($attr) => [
                'name' => $attr['name'],
                'value' => $attr['value'],
                'unit' => $attr['unit'],
            ], $item['attributes']),
            'flags' => [
                'is_new' => $item['is_new'],
                'is_bestseller' => $item['is_bestseller'],
            ],
            'logistics' => [
                'weight_gross_kg' => $item['weight_gross'],
                'weight_net_kg' => $item['weight_net'],
                'width_m' => $item['width'],
                'height_m' => $item['height'],
                'depth_m' => $item['depth'],
                'hs_code' => $item['hs_code'],
            ],
        ];

        return $product;
    }

    /**
     * Строит дерево категорий с вложенностью.
     */
    protected function buildCategoryTree($categories): array
    {
        $indexed = [];
        foreach ($categories as $cat) {
            $indexed[$cat->id] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug ?? null,
                'parent_id' => $cat->parent_id,
                'children' => [],
            ];
        }

        $tree = [];
        foreach ($indexed as $id => &$node) {
            if ($node['parent_id'] && isset($indexed[$node['parent_id']])) {
                $indexed[$node['parent_id']]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        // Убираем parent_id из финального вывода (уже выражен через вложенность)
        $this->cleanParentIds($tree);

        return $tree;
    }

    /**
     * Рекурсивно убирает parent_id из дерева.
     */
    protected function cleanParentIds(array &$nodes): void
    {
        foreach ($nodes as &$node) {
            unset($node['parent_id']);
            if (! empty($node['children'])) {
                $this->cleanParentIds($node['children']);
            } else {
                unset($node['children']);
            }
        }
    }
}

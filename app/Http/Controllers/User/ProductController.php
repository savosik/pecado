<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Product\ProductQueryService;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index()
    {
        return Inertia::render('User/Products/Index');
    }

    public function show(Product $product)
    {
        // Подгрузка связей
        $product->load([
            'category.ancestors',
            'brand',
            'model',
            'certificates.media',
            'attributeValues.attribute',
            'attributeValues.attributeValue',
            'media',
            'barcodes',
            'tags',
        ]);

        // Хлебные крошки — цепочка категорий от корня до текущей
        $categoryTrail = [];
        if ($product->category) {
            $ancestors = $product->category->ancestors->sortBy('_lft');
            foreach ($ancestors as $ancestor) {
                $categoryTrail[] = [
                    'id'        => $ancestor->id,
                    'name'      => $ancestor->name,
                    'slug'      => $ancestor->slug,
                    'parent_id' => $ancestor->parent_id,
                ];
            }
            $categoryTrail[] = [
                'id'        => $product->category->id,
                'name'      => $product->category->name,
                'slug'      => $product->category->slug,
                'parent_id' => $product->category->parent_id,
            ];
        }

        // Варианты — другие товары той же ProductModel
        $variants = [];
        if ($product->model_id) {
            $variantProducts = Product::where('model_id', $product->model_id)
                ->where('id', '!=', $product->id)
                ->with(['brand', 'media', 'tags', 'attributeValues.attribute', 'attributeValues.attributeValue'])
                ->get();

            // Собираем атрибуты ВСЕХ товаров модели (включая текущий) для сравнения.
            // Исключаем технические атрибуты (number/boolean типы, упаковка, весá и т.д.)
            $allProducts = $variantProducts->push($product);
            $excludedTypes = ['number', 'boolean'];
            $excludedNames = [
                'Composition', 'Классификация для отчетности',
                'Подходит для маркетплейсов', 'Маркированный товар',
                'Рекомендации по применению',
                'Количество в комплекте', 'Количество изделий в розничной упаковке',
                'Коробок в упаковке',
            ];
            $attrMap = []; // attr_name => [product_id => value]
            foreach ($allProducts as $p) {
                foreach ($p->attributeValues as $av) {
                    $attr = $av->attribute;
                    if (!$attr) continue;
                    if (in_array($attr->type, $excludedTypes)) continue;
                    if (in_array($attr->name, $excludedNames)) continue;

                    $value = $av->attributeValue?->value ?? $av->text_value;
                    if ($value !== null && $value !== '') {
                        $attrMap[$attr->name][$p->id] = $value;
                    }
                }
            }

            // Находим отличающиеся атрибуты — те, где значения не у всех одинаковые
            $diffAttrNames = [];
            foreach ($attrMap as $attrName => $productValues) {
                $unique = array_unique(array_values($productValues));
                if (count($unique) > 1) {
                    $diffAttrNames[] = $attrName;
                }
            }

            // Обогащаем остатками/скидками/валютой
            $variantArrays = $variantProducts->map(fn ($p) => ProductQueryService::productToArray($p))->values()->toArray();
            $variantArrays = ProductQueryService::enrichProductsWithStock($variantArrays);
            $variantArrays = ProductQueryService::enrichProductsWithDiscounts($variantArrays);
            $variantArrays = ProductQueryService::convertProductsPrices($variantArrays);

            // Добавляем diff_attrs к каждому варианту
            foreach ($variantArrays as &$va) {
                $va['diff_attrs'] = [];
                foreach ($diffAttrNames as $an) {
                    if (isset($attrMap[$an][$va['id']])) {
                        $va['diff_attrs'][] = [
                            'name'  => $an,
                            'value' => $attrMap[$an][$va['id']],
                        ];
                    }
                }
            }
            unset($va);

            $variants = $variantArrays;
        }

        // Медиа — основное изображение + дополнительные + видео
        $media = [];
        $mainUrl = $product->getFirstMediaUrl('main');
        if ($mainUrl) {
            $media[] = ['url' => $mainUrl, 'type' => 'image'];
        }
        foreach ($product->getMedia('additional') as $m) {
            $media[] = ['url' => $m->getUrl(), 'type' => 'image'];
        }
        $videoUrl = $product->getFirstMediaUrl('video');
        if ($videoUrl) {
            $media[] = ['url' => $videoUrl, 'type' => 'video'];
        }

        // Сертификаты
        $certificates = $product->certificates->map(function ($cert) {
            $file = $cert->getFirstMedia('files');
            return [
                'id'   => $cert->id,
                'name' => $cert->name,
                'url'  => $file ? $file->getUrl() : null,
            ];
        })->values()->toArray();

        // Характеристики (атрибуты)
        $specifications = [];
        foreach ($product->attributeValues as $av) {
            $attrName = $av->attribute?->name;
            if (!$attrName) continue;

            // Значение: берём из справочника или текстовое/числовое/булево
            $value = $av->attributeValue?->value
                ?? $av->text_value
                ?? $av->number_value
                ?? ($av->boolean_value !== null ? ($av->boolean_value ? 'Да' : 'Нет') : null);

            if ($value !== null && $value !== '') {
                $specifications[$attrName] = (string) $value;
            }
        }

        // Основные данные товара
        $productData = ProductQueryService::productToArray($product);

        // Обогащаем остатками, скидками, валютой
        $enriched = ProductQueryService::enrichProductsWithStock([$productData]);
        $enriched = ProductQueryService::enrichProductsWithDiscounts($enriched);
        $enriched = ProductQueryService::convertProductsPrices($enriched);
        $productData = $enriched[0];

        // Дополнительные поля для детальной страницы
        $productData['code'] = $product->code;
        $productData['barcode'] = $product->barcode;
        $productData['description'] = $product->description;
        $productData['description_html'] = $product->description_html;
        $productData['barcodes'] = $product->barcodes->map(fn ($b) => [
            'id'      => $b->id,
            'barcode' => $b->barcode,
        ])->values()->toArray();
        $productData['brand'] = $product->brand ? [
            'name' => $product->brand->name,
            'slug' => $product->brand->slug,
        ] : null;
        $productData['category'] = $product->category ? [
            'name' => $product->category->name,
            'slug' => $product->category->slug,
        ] : null;
        $productData['model_name'] = $product->model?->name;

        return Inertia::render('User/Products/Show', [
            'product'        => $productData,
            'media'          => $media,
            'categoryTrail'  => $categoryTrail,
            'variants'       => $variants,
            'certificates'   => $certificates,
            'specifications' => $specifications,
        ]);
    }
}

<?php

namespace App\Http\Controllers\User;

use App\Enums\CatalogSort;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Services\Product\ProductQueryService;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Каталог товаров — главная страница.
     * GET /products
     */
    public function index(): Response
    {
        $appName = config('app.name');

        $canonical = route('products.index');

        return $this->renderCatalog([
            'seo' => [
                'title' => "Каталог товаров — {$appName}",
                'description' => "Каталог товаров интернет-магазина {$appName}",
                'h1' => 'Каталог товаров',
                'canonical' => $canonical,
                'url' => $canonical,
            ],
        ]);
    }

    /**
     * Каталог по бренду.
     * GET /brands/{brand:slug}
     */
    public function byBrand(Brand $brand): Response
    {
        $appName = config('app.name');

        $canonical = route('products.brand', $brand);

        return $this->renderCatalog([
            'seo' => [
                'title' => $brand->meta_title ?: "{$brand->name} — каталог в {$appName}",
                'description' => $brand->meta_description ?: "Товары бренда {$brand->name} в интернет-магазине {$appName}",
                'h1' => $brand->name,
                'canonical' => $canonical,
                'url' => $canonical,
            ],
            'pageDescription' => $brand->short_description,
            'initialFilters' => [
                'brand_ids' => [$brand->id],
            ],
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
            ],
            'breadcrumbs' => [
                ['label' => 'Каталог', 'url' => route('products.index')],
                ['label' => $brand->name, 'url' => null],
            ],
        ]);
    }

    /**
     * Каталог по категории.
     * GET /categories/{category:slug}
     */
    public function byCategory(Category $category): Response
    {
        // Неактивная категория — 404 для пользователя
        abort_if(! $category->is_active, 404);

        $appName = config('app.name');

        // Хлебные крошки: Каталог → предки → текущая категория
        $ancestors = $category->ancestors()->orderBy('_lft')->get();

        $breadcrumbs = [
            ['label' => 'Каталог', 'url' => route('products.index')],
        ];

        // categoryTrail для ProductBreadcrumbs (с parent_id для siblings)
        $categoryTrail = [];

        foreach ($ancestors as $ancestor) {
            $breadcrumbs[] = [
                'label' => $ancestor->name,
                'url' => route('products.category', $ancestor->slug),
            ];
            $categoryTrail[] = [
                'id' => $ancestor->id,
                'name' => $ancestor->name,
                'slug' => $ancestor->slug,
                'parent_id' => $ancestor->parent_id,
            ];
        }
        $breadcrumbs[] = ['label' => $category->name, 'url' => null];
        $categoryTrail[] = [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
        ];

        $canonical = route('products.category', $category);

        return $this->renderCatalog([
            'seo' => [
                'title' => $category->meta_title ?: "{$category->name} — купить в {$appName}",
                'description' => $category->meta_description ?: "Купить {$category->name} в интернет-магазине {$appName}",
                'h1' => $category->name,
                'canonical' => $canonical,
                'url' => $canonical,
            ],
            'pageDescription' => $category->description,
            'initialFilters' => [
                'category_id' => $category->id,
            ],
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
            'categoryTrail' => $categoryTrail,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    /**
     * Каталог по подборке (коллекции).
     * GET /collections/{selection:slug}
     */
    public function bySelection(ProductSelection $selection): Response
    {
        $appName = config('app.name');

        $canonical = route('products.selection', $selection);

        return $this->renderCatalog([
            'seo' => [
                'title' => $selection->meta_title ?: "{$selection->name} — {$appName}",
                'description' => $selection->meta_description ?: "{$selection->name} — подборка товаров в {$appName}",
                'h1' => $selection->name,
                'canonical' => $canonical,
                'url' => $canonical,
            ],
            'pageDescription' => $selection->description,
            'initialFilters' => [
                'collection_ids' => [$selection->id],
            ],
            'selection' => [
                'id' => $selection->id,
                'name' => $selection->name,
                'slug' => $selection->slug,
            ],
            'breadcrumbs' => [
                ['label' => 'Каталог', 'url' => route('products.index')],
                ['label' => $selection->name, 'url' => null],
            ],
        ]);
    }

    /**
     * Каталог избранных товаров.
     * GET /products/favorites
     */
    public function favorites(): Response
    {
        $appName = config('app.name');

        $canonical = route('products.favorites');

        return $this->renderCatalog([
            'seo' => [
                'title' => "Избранные товары — {$appName}",
                'description' => "Ваши избранные товары в {$appName}",
                'h1' => 'Избранные товары',
                'canonical' => $canonical,
                'url' => $canonical,
            ],
            'initialFilters' => [
                'in_favourites' => 1,
            ],
        ]);
    }

    /**
     * Каталог новинок.
     * GET /products/novinki
     */
    public function novelties(): Response
    {
        $appName = config('app.name');

        $canonical = route('products.novelties');

        return $this->renderCatalog([
            'seo' => [
                'title' => "Новинки — {$appName}",
                'description' => "Новинки интернет-магазина {$appName}",
                'h1' => 'Новинки',
                'canonical' => $canonical,
                'url' => $canonical,
            ],
            'initialFilters' => [
                'is_new' => 1,
            ],
            'breadcrumbs' => [
                ['label' => 'Каталог', 'url' => route('products.index')],
                ['label' => 'Новинки', 'url' => null],
            ],
        ]);
    }

    /**
     * Каталог бестселлеров.
     * GET /products/bestsellery
     */
    public function bestsellers(): Response
    {
        $appName = config('app.name');

        $canonical = route('products.bestsellers');

        return $this->renderCatalog([
            'seo' => [
                'title' => "Бестселлеры — {$appName}",
                'description' => "Бестселлеры интернет-магазина {$appName}",
                'h1' => 'Бестселлеры',
                'canonical' => $canonical,
                'url' => $canonical,
            ],
            'initialFilters' => [
                'is_bestseller' => 1,
            ],
            'breadcrumbs' => [
                ['label' => 'Каталог', 'url' => route('products.index')],
                ['label' => 'Бестселлеры', 'url' => null],
            ],
        ]);
    }

    /**
     * Карточка товара.
     * GET /products/{product:slug}
     */
    public function show(Product $product): Response
    {
        $data = $this->buildProductShowData($product);

        return Inertia::render('User/Products/Show', $data);
    }

    /**
     * JSON-ответ карточки товара (для QuickView диалога).
     * GET /api/products/{product:slug}
     */
    public function showJson(Product $product): \Illuminate\Http\JsonResponse
    {
        $data = $this->buildProductShowData($product);

        return response()->json($data);
    }

    /**
     * Собирает все данные для детальной страницы товара.
     */
    private function buildProductShowData(Product $product): array
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
            'sizeChart',
        ]);

        // Хлебные крошки — цепочка категорий от корня до текущей
        $categoryTrail = [];
        if ($product->category) {
            $ancestors = $product->category->ancestors->sortBy('_lft');
            foreach ($ancestors as $ancestor) {
                $categoryTrail[] = [
                    'id' => $ancestor->id,
                    'name' => $ancestor->name,
                    'slug' => $ancestor->slug,
                    'parent_id' => $ancestor->parent_id,
                ];
            }
            $categoryTrail[] = [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
                'parent_id' => $product->category->parent_id,
            ];
        }

        // Варианты — другие товары той же ProductModel
        $variants = [];
        if ($product->model_id) {
            $variantProducts = Product::where('model_id', $product->model_id)
                ->with(['brand', 'media', 'tags', 'attributeValues.attribute', 'attributeValues.attributeValue'])
                ->get();

            // Собираем атрибуты ВСЕХ товаров модели для сравнения.
            // Исключаем технические атрибуты (number/boolean типы, упаковка, весá и т.д.)
            $allProducts = $variantProducts;
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
                    if (! $attr) {
                        continue;
                    }
                    if (! $attr->is_active || ! $attr->show_on_site) {
                        continue;
                    }
                    if (in_array($attr->type, $excludedTypes)) {
                        continue;
                    }
                    if (in_array($attr->name, $excludedNames)) {
                        continue;
                    }

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
                            'name' => $an,
                            'value' => $attrMap[$an][$va['id']],
                        ];
                    }
                }
            }
            unset($va);

            // Сортируем варианты по артикулу (SKU)
            usort($variantArrays, function ($a, $b) {
                return strnatcasecmp($a['sku'] ?? '', $b['sku'] ?? '');
            });

            // Скрываем варианты «нет в наличии» (stock=0 и preorder=0),
            // но текущий товар всегда остаётся видимым
            $variantArrays = array_values(array_filter($variantArrays, function ($va) use ($product) {
                if ($va['id'] === $product->id) {
                    return true;
                }

                return ($va['stock_quantity'] ?? 0) > 0 || ($va['preorder_quantity'] ?? 0) > 0;
            }));

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
                'id' => $cert->id,
                'name' => $cert->name,
                'url' => $file ? $file->getUrl() : null,
            ];
        })->values()->toArray();

        // Характеристики (атрибуты)
        $specifications = [];
        foreach ($product->attributeValues as $av) {
            $attr = $av->attribute;
            $attrName = $attr?->name;
            if (! $attrName) {
                continue;
            }

            if (! $attr->is_active || ! $attr->show_on_site) {
                continue;
            }

            // Значение: приоритет по типу атрибута, чтобы boolean не вылезал как "1"
            $value = $av->getFormattedValue();
            if ($value === '' && $attr->isSelect()) {
                $value = $av->attributeValue?->value ?? '';
            }

            if ($value !== '' && $value !== null) {
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
        $productData['rich_content'] = $product->rich_content;
        $productData['short_description'] = $product->short_description;
        $productData['barcodes'] = $product->barcodes->map(fn ($b) => [
            'id' => $b->id,
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

        // Размерная сетка
        $sizeChart = null;
        if ($product->sizeChart) {
            $sizeChart = [
                'name' => $product->sizeChart->name,
                'values' => $product->sizeChart->values,
            ];
        }

        return [
            'product' => $productData,
            'media' => $media,
            'categoryTrail' => $categoryTrail,
            'variants' => $variants,
            'certificates' => $certificates,
            'specifications' => $specifications,
            'sizeChart' => $sizeChart,
        ];
    }

    /**
     * JSON: корневые категории (для breadcrumbs siblings).
     * GET /api/categories
     */
    public function categoriesRoot(): \Illuminate\Http\JsonResponse
    {
        $categories = Category::active()->whereIsRoot()
            ->orderBy('_lft')
            ->get(['id', 'name', 'slug', 'parent_id']);

        return response()->json(['categories' => $categories]);
    }

    /**
     * JSON: категория с дочерними (для breadcrumbs siblings).
     * GET /api/categories/{category}
     */
    public function categoryShow(Category $category): \Illuminate\Http\JsonResponse
    {
        abort_if(! $category->is_active, 404);

        $children = $category->children()
            ->where('is_active', true)
            ->orderBy('_lft')
            ->get(['id', 'name', 'slug', 'parent_id']);

        return response()->json([
            'category' => $category->only(['id', 'name', 'slug', 'parent_id']),
            'children' => $children,
        ]);
    }

    /**
     * Приватный хелпер — рендер единой Inertia-страницы каталога.
     */
    private function renderCatalog(array $props): Response
    {
        $props['sortOptions'] = CatalogSort::options();

        return Inertia::render('User/Products/Index', $props);
    }
}

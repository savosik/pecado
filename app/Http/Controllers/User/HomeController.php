<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Product\ProductQueryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $regionId = Auth::user()?->region_id;
        $selections = ProductSelectionController::getCachedSelections($regionId);

        // Новинки и бестселлеры — запрос с фильтрацией по остаткам региона пользователя
        $wh = ProductQueryService::getRegionWarehouseIds();
        $allWarehouseIds = array_merge($wh['primary'], $wh['preorder']);

        $newProducts = $this->getProductsWithStock('is_new', $allWarehouseIds, 10);
        $bestsellers = $this->getProductsWithStock('is_bestseller', $allWarehouseIds, 10);

        // Обогащаем остатками (для отображения stock_quantity/preorder_quantity на карточках)
        $selections = ProductQueryService::enrichSelectionsWithStock($selections);
        $newProducts = ProductQueryService::enrichProductsWithStock($newProducts);
        $bestsellers = ProductQueryService::enrichProductsWithStock($bestsellers);

        // Обогащаем скидками
        $selections = ProductQueryService::enrichSelectionsWithDiscounts($selections);
        $newProducts = ProductQueryService::enrichProductsWithDiscounts($newProducts);
        $bestsellers = ProductQueryService::enrichProductsWithDiscounts($bestsellers);

        // Конвертируем валюту
        $selections = ProductQueryService::convertSelectionsPrices($selections);
        $newProducts = ProductQueryService::convertProductsPrices($newProducts);
        $bestsellers = ProductQueryService::convertProductsPrices($bestsellers);

        // Маркеры акции — после кеша: флаг меняется при каждом включении правила,
        // в кешированный снимок его класть нельзя
        $selections = ProductQueryService::enrichSelectionsWithPromotions($selections);
        $newProducts = ProductQueryService::enrichProductsWithPromotions($newProducts);
        $bestsellers = ProductQueryService::enrichProductsWithPromotions($bestsellers);

        return Inertia::render('User/Home', [
            'banners' => BannerController::getCachedBanners($regionId),
            'stories' => StoryController::getCachedStories($regionId),
            'productSelections' => $selections,
            'newProducts' => $newProducts,
            'bestsellerProducts' => $bestsellers,
            'seo' => [
                'title' => 'Pecado — товары для взрослых: секс-игрушки, бренды, доставка',
                'description' => 'Pecado — большой выбор товаров для взрослых: секс-игрушки, БДСМ, интимная косметика, презервативы, БАДы. Топовые бренды, наличие на складе, доставка по России.',
                'keywords' => 'товары для взрослых, секс-игрушки, интим товары, интимная косметика, бдсм, презервативы',
                'h1' => 'Товары для взрослых',
                'url' => url('/'),
                'canonical' => url('/'),
                'image' => url('/images/logo.png'),
                'type' => 'website',
                'structured_data' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => 'Pecado',
                    'url' => url('/'),
                    'logo' => url('/images/logo.svg'),
                ],
            ],
            'seoText' => $this->homeSeoText(),
        ]);
    }

    /**
     * SEO-текст главной (нижний сворачиваемый блок).
     * Хранится в seo/texts/home.html (как тексты категорий), деплоится через git.
     */
    private function homeSeoText(): ?string
    {
        $path = base_path('seo/texts/home.html');

        return is_file($path) ? (trim((string) file_get_contents($path)) ?: null) : null;
    }

    /**
     * Загрузить товары с флагом ($flag = is_new / is_bestseller),
     * у которых есть остатки на складах региона пользователя.
     *
     * @param  string  $flag  Название boolean-столбца (is_new, is_bestseller)
     * @param  int[]  $warehouseIds  Все склады региона (primary + preorder)
     */
    private function getProductsWithStock(string $flag, array $warehouseIds, int $limit): array
    {
        $query = Product::where($flag, true)
            ->with(ProductQueryService::productEagerLoads())
            ->latest();

        // Если есть склады региона — оставляем только товары с остатками > 0
        if (! empty($warehouseIds)) {
            $query->whereExists(function ($sub) use ($warehouseIds) {
                $sub->select(DB::raw(1))
                    ->from('product_warehouse')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('product_warehouse.warehouse_id', $warehouseIds)
                    ->where('product_warehouse.quantity', '>', 0);
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn ($p) => ProductQueryService::productToArray($p))
            ->values()
            ->toArray();
    }
}

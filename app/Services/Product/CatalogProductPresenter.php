<?php

namespace App\Services\Product;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Приведение товаров каталога к JSON-виду для витрины.
 *
 * Общий формат для каталога и поиска: карточка в сетке обязана выглядеть
 * одинаково, откуда бы товар ни пришёл (БД-фильтр или Meilisearch).
 */
class CatalogProductPresenter
{
    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $products): array
    {
        $items = $products
            ->map(fn (Product $product) => ProductQueryService::productToArray($product))
            ->values()
            ->toArray();

        // Обогащение скидками и конвертация валют
        $items = ProductQueryService::enrichProductsWithDiscounts($items);
        $items = ProductQueryService::convertProductsPrices($items);

        // Маркеры акции (region-aware): участие в акции и «выдаётся как промо-позиция».
        // Обогатитель общий для каталога, карточки товара, подборок, избранного и поиска —
        // бейдж в сетке обязан совпадать с тем, что видно на детальной странице.
        $items = ProductQueryService::enrichProductsWithPromotions($items);

        // Маркер уценки (некондиции) — еле заметный значок в карточке каталога.
        // Булев флаг, не ценовой: отдаётся и гостям.
        $items = ProductQueryService::enrichProductsWithDefects($items);

        $user = Auth::user();

        // Добавляем is_favorited для авторизованных пользователей
        if ($user) {
            $productIds = collect($items)->pluck('id')->toArray();
            $favoritedIds = DB::table('favorites')
                ->where('user_id', $user->id)
                ->whereIn('product_id', $productIds)
                ->pluck('product_id')
                ->flip()
                ->toArray();

            return array_map(function ($product) use ($favoritedIds) {
                $product['is_favorited'] = isset($favoritedIds[$product['id']]);

                return $product;
            }, $items);
        }

        // Для гостей: убираем ценовые и складские данные из ответа
        return array_map(function ($product) {
            $product['is_favorited'] = false;
            unset(
                $product['base_price'],
                $product['sale_price'],
                $product['discount_percentage'],
                $product['stock_quantity'],
                $product['preorder_quantity'],
            );

            return $product;
        }, $items);
    }
}

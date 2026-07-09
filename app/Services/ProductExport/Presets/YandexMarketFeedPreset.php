<?php

namespace App\Services\ProductExport\Presets;

use App\Contracts\Pricing\PriceResult;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Публичный YML-фид для Яндекс.Маркета.
 *
 * В отличие от {@see YmlPreset} (партнёрская выгрузка через личный кабинет
 * с индивидуальными ценами), этот пресет формирует единый публичный прайс:
 *
 *  - только товары активных категорий (как витрина сайта), с ценой > 0 и
 *    с остатком > 0 на складе «Москва Основной» (config feed.yandex_market.warehouse);
 *  - цена — розничная `base_price`, без привязки к пользователю и без oldprice;
 *  - `<count>` и `available` берутся из остатка того же склада.
 *
 * Фильтр по складу резко сокращает размер фида (только реально доступный к
 * отгрузке товар) — исходный фид «все активные категории» был слишком велик.
 *
 * Не регистрируется в PresetRegistry — не появляется в UI выгрузок кабинета.
 */
class YandexMarketFeedPreset extends YmlPreset
{
    public function key(): string
    {
        return 'yandex-market';
    }

    public function name(): string
    {
        return 'Публичный YML-фид Яндекс.Маркета';
    }

    /**
     * ID склада, по наличию на котором формируется фид. Резолвится по имени
     * из конфига один раз на инстанс. Если склад не найден — null, и запрос
     * ниже вернёт пустой фид (с предупреждением в лог).
     */
    protected function warehouseId(): ?int
    {
        return once(function () {
            $name = (string) config('feed.yandex_market.warehouse');
            $id = Warehouse::where('name', $name)->value('id');

            if ($id === null) {
                Log::warning('feed.yandex_market.warehouse_not_found', ['name' => $name]);
            }

            return $id;
        });
    }

    /**
     * Товары активных категорий, с ценой > 0 и остатком > 0 на целевом складе.
     * Дополнительно подтягиваем остаток этого склада скалярным подзапросом
     * (feed_stock) — уникальность (product_id, warehouse_id) гарантирует одну строку.
     *
     * @return Builder<Product>
     */
    protected function buildBaseQuery(): Builder
    {
        $warehouseId = $this->warehouseId();

        return parent::buildBaseQuery()
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->where('base_price', '>', 0)
            ->whereHas('warehouses', fn ($q) => $q
                ->where('warehouses.id', $warehouseId)
                ->where('product_warehouse.quantity', '>', 0))
            ->addSelect(['feed_stock' => DB::table('product_warehouse')
                ->select('quantity')
                ->whereColumn('product_warehouse.product_id', 'products.id')
                ->where('warehouse_id', $warehouseId)
                ->limit(1),
            ]);
    }

    /**
     * Публичный фид всегда отдаёт розничную цену (`base_price`) и остаток
     * целевого склада (feed_stock), а не региональную сумму ценового/складского
     * сервиса. Детерминированно соответствует бизнес-логике «розница + Москва».
     */
    protected function mapProduct(Product $product, ?User $clientUser, ?PriceResult $priceResult, int $stockAvailable): array
    {
        $row = parent::mapProduct($product, $clientUser, $priceResult, $stockAvailable);
        $row['price'] = round((float) $product->base_price, 2);
        $row['stock'] = (int) ($product->getAttribute('feed_stock') ?? 0);

        return $row;
    }
}

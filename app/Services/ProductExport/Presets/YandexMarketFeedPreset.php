<?php

namespace App\Services\ProductExport\Presets;

use App\Contracts\Pricing\PriceResult;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Публичный YML-фид для Яндекс.Маркета.
 *
 * В отличие от {@see YmlPreset} (партнёрская выгрузка через личный кабинет
 * с индивидуальными ценами), этот пресет формирует единый публичный прайс:
 *
 *  - только товары активных категорий (как витрина сайта — см.
 *    CatalogApiController::buildBaseQuery) и с ненулевой ценой;
 *  - цена — розничная `base_price`, без привязки к какому-либо пользователю
 *    (client_user_id = null), без акционных oldprice;
 *  - `available` выставляется по остаткам склада.
 *
 * Не регистрируется в PresetRegistry — не должен появляться в UI выгрузок
 * кабинета. Используется только билдером публичного фида.
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
     * Только товары активных категорий и с ценой > 0 — чтобы фид совпадал
     * с витриной и не содержал невалидных для Яндекса офферов без цены.
     *
     * @return Builder<Product>
     */
    protected function buildBaseQuery(): Builder
    {
        return parent::buildBaseQuery()
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->where('base_price', '>', 0);
    }

    /**
     * Публичный фид всегда отдаёт розничную цену (`base_price`), независимо
     * от того, что вернул ценовой сервис для гостя. Это детерминированно
     * соответствует выбранной бизнес-логике «розничная цена, без скидок».
     */
    protected function mapProduct(Product $product, ?User $clientUser, ?PriceResult $priceResult, int $stockAvailable): array
    {
        $row = parent::mapProduct($product, $clientUser, $priceResult, $stockAvailable);
        $row['price'] = round((float) $product->base_price, 2);

        return $row;
    }
}

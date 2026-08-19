<?php

namespace App\Services\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Currency;
use App\Models\Product;
use App\Models\User;

class PriceService implements PriceServiceInterface
{
    public function __construct(
        protected CurrencyConversionServiceInterface $currencyService,
        protected UserCurrencyResolverInterface $currencyResolver
    ) {}

    /**
     * Склад, чья индивидуальная цена действует для товара в регионе со стопкой
     * складов, — «победитель» по остаткам. Резолвится здесь, в единой точке
     * выдачи цены, чтобы все потребители (карточка, корзина, каталог, промо,
     * экспорт) видели одну и ту же цену. Для регионов без стопки — null
     * (StockService отвечает без SQL по остаткам, только мемоизированный
     * резолв региона).
     *
     * @param  list<int>  $productIds
     * @return array<int, int|null>
     */
    protected function winningWarehouseMap(array $productIds, User $user): array
    {
        return app(StockServiceInterface::class)->getWinningWarehouseMap($productIds, $user);
    }

    /**
     * Get the base price of the product in the base currency.
     */
    public function getBasePrice(Product $product): float
    {
        return (float) $product->base_price;
    }

    /**
     * Get the base price of the product converted to the user's preferred currency (no discounts).
     */
    public function getBasePriceForUser(Product $product, ?User $user = null): float
    {
        $basePrice = $this->getBasePrice($product);

        if ($user) {
            $currency = $this->currencyResolver->resolve($user);
            if ($currency) {
                return $this->convertPrice($basePrice, $currency);
            }
        }

        return $basePrice;
    }

    /**
     * Get the price of the product for a specific user in their preferred currency.
     * Uses individual_prices from 1С if available, otherwise returns base price.
     */
    public function getUserPrice(Product $product, ?User $user = null, ?int $warehouseId = null): float
    {
        $priceResult = $this->getPriceResult($product, $user, $warehouseId);
        $displayPrice = $priceResult->getDisplayPrice();

        if ($user) {
            $currency = $this->currencyResolver->resolve($user);
            if ($currency) {
                return $this->convertPrice($displayPrice, $currency);
            }
        }

        return $displayPrice;
    }

    /**
     * Get the full price result with base, individual, and discount info.
     *
     * v7: Вместо расчёта скидок на сайте — берём готовую индивидуальную цену из таблицы
     * individual_prices, куда 1С выгружает рассчитанные цены через MinIO.
     */
    public function getPriceResult(Product $product, ?User $user = null, ?int $warehouseId = null): PriceResult
    {
        $basePrice = $this->getBasePrice($product);

        if (! $user || ! $user->erp_id) {
            return PriceResult::withoutDiscount($basePrice);
        }

        // Регион со стопкой складов: без явного склада действует цена
        // склада-победителя по остаткам. Для регионов без стопки карта
        // вернёт null — прежнее поведение (детерминированная строка без
        // складского фильтра).
        $warehouseId ??= $this->winningWarehouseMap([(int) $product->id], $user)[$product->id] ?? null;

        // v7.1: Ищем по числовым ID (partner_id = user.id, product_id = product.id)
        // Через proxy для graceful degradation при недоступности prices DB
        $individualPrice = IndividualPriceProxy::findPrice($user->id, $product->id, $warehouseId);

        if (! $individualPrice) {
            return PriceResult::withoutDiscount($basePrice);
        }

        return PriceResult::withIndividualPrice($basePrice, (float) $individualPrice->price);
    }

    /**
     * Получить карту PriceResult для коллекции товаров одним запросом в prices DB.
     * Без user или без erp_id — все товары без скидки. Если loadPriceMap отдала пусто
     * (нет индивидуальных цен или prices DB недоступна) — все товары без скидки.
     *
     * $warehouseMap (product_id → warehouse_id победителя стопки) по умолчанию
     * резолвится автоматически; передавайте явно, только если победители уже
     * известны (например, зафиксированы в заказе).
     *
     * @param  iterable<Product>  $products
     * @param  array<int, int|null>|null  $warehouseMap
     * @return array<int, PriceResult>
     */
    public function getPriceMapForProducts(iterable $products, ?User $user = null, ?array $warehouseMap = null): array
    {
        $productList = [];
        $productIds = [];
        foreach ($products as $product) {
            $productList[] = $product;
            $productIds[] = (int) $product->id;
        }

        if ($productList === []) {
            return [];
        }

        if ($user && $user->erp_id && $warehouseMap === null) {
            $warehouseMap = $this->winningWarehouseMap($productIds, $user);
        }

        $priceMap = ($user && $user->erp_id)
            ? IndividualPriceProxy::loadPriceMap($user->id, $productIds, $warehouseMap)
            : collect();

        $result = [];
        foreach ($productList as $product) {
            $basePrice = $this->getBasePrice($product);
            $individualPrice = $priceMap->get($product->id);

            $result[$product->id] = $individualPrice !== null
                ? PriceResult::withIndividualPrice($basePrice, (float) $individualPrice)
                : PriceResult::withoutDiscount($basePrice);
        }

        return $result;
    }

    /**
     * Get the price of the product in a specific currency.
     */
    public function getCurrencyPrice(Product $product, Currency $currency): float
    {
        $basePrice = $this->getBasePrice($product);

        return $this->convertPrice($basePrice, $currency);
    }

    /**
     * Convert an arbitrary amount from base currency to target currency.
     */
    public function convertPrice(float $amount, Currency $currency): float
    {
        return $this->currencyService->convertFromBase($amount, $currency);
    }
}

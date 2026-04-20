<?php

namespace App\Services\Pricing;

use App\Models\IndividualPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IndividualPriceStatsService
{
    private const CACHE_KEY = 'individual_prices_stats';

    private const CACHE_KEY_PARTNER_IDS = 'individual_prices_distinct_partner_ids';

    private const CACHE_KEY_PRODUCT_IDS = 'individual_prices_distinct_product_ids';

    private const CACHE_KEY_WAREHOUSE_IDS = 'individual_prices_distinct_warehouse_ids';

    // COUNT(*) и COUNT(DISTINCT) по 64 HASH-партициям — тяжёлые запросы,
    // кешируем на 30 минут (точность для админ-дашборда не критична).
    private const CACHE_TTL_SECONDS = 1800;

    // distinct-ID нужны для фильтрации в поиске — обновляем чаще, но не при каждом запросе.
    private const CACHE_TTL_IDS_SECONDS = 300;

    /**
     * @return array{total_prices: int, total_partners: int}
     */
    public function get(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            try {
                return [
                    'total_prices' => IndividualPrice::count(),
                    'total_partners' => DB::connection('prices')
                        ->table('individual_prices')
                        ->distinct()
                        ->count('partner_id'),
                ];
            } catch (\Throwable) {
                return [
                    'total_prices' => 0,
                    'total_partners' => 0,
                ];
            }
        });
    }

    /**
     * Кешированный список distinct partner_id (для фильтра в поиске).
     */
    public function getDistinctPartnerIds(): Collection
    {
        return Cache::remember(self::CACHE_KEY_PARTNER_IDS, self::CACHE_TTL_IDS_SECONDS, fn () => DB::connection('prices')->table('individual_prices')->distinct()->pluck('partner_id')
        );
    }

    /**
     * Кешированный список distinct product_id (для фильтра в поиске).
     */
    public function getDistinctProductIds(): Collection
    {
        return Cache::remember(self::CACHE_KEY_PRODUCT_IDS, self::CACHE_TTL_IDS_SECONDS, fn () => DB::connection('prices')->table('individual_prices')->distinct()->pluck('product_id')
        );
    }

    /**
     * Кешированный список distinct warehouse_id (для фильтра в поиске).
     */
    public function getDistinctWarehouseIds(): Collection
    {
        return Cache::remember(self::CACHE_KEY_WAREHOUSE_IDS, self::CACHE_TTL_IDS_SECONDS, fn () => DB::connection('prices')->table('individual_prices')->distinct()->pluck('warehouse_id')
        );
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY_PARTNER_IDS);
        Cache::forget(self::CACHE_KEY_PRODUCT_IDS);
        Cache::forget(self::CACHE_KEY_WAREHOUSE_IDS);
    }
}

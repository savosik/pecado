<?php

namespace App\Services\Pricing;

use App\Models\IndividualPrice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IndividualPriceStatsService
{
    private const CACHE_KEY = 'individual_prices_stats';

    private const CACHE_TTL_SECONDS = 300;

    /**
     * Точная статистика по отдельной prices DB.
     * Кеш короткий, чтобы цифры оставались актуальными в админке.
     *
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

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}

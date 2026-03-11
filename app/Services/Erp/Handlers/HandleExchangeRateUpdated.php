<?php

namespace App\Services\Erp\Handlers;

use App\Models\Currency;
use Illuminate\Support\Facades\Log;

class HandleExchangeRateUpdated
{
    /**
     * Обработка события exchange_rate.updated из 1С.
     *
     * Находит валюту по currency_code и обновляет:
     * - official_rate   — курс нацбанка
     * - rate_coefficient — поправочный коэффициент (rate_coefficient из payload)
     * - exchange_rate   — итоговый курс (= official_rate × rate_coefficient)
     * - exchange_rate_date — дата курса
     *
     * Базовая валюта всегда RUB. Если валюта не найдена — событие игнорируется.
     */
    public function handle(array $payload): void
    {
        $currencyCode   = $payload['currency_code'] ?? null;
        $rate           = $payload['rate'] ?? null;
        $officialRate   = $payload['official_rate'] ?? null;
        $rateCoefficient = $payload['rate_coefficient'] ?? null;
        $date           = $payload['date'] ?? null;

        if (!$currencyCode || $rate === null) {
            Log::warning('exchange_rate.updated: отсутствует currency_code или rate', ['payload' => $payload]);

            return;
        }

        $currency = Currency::where('code', $currencyCode)->first();

        if (!$currency) {
            Log::info('exchange_rate.updated: валюта не найдена по коду, событие проигнорировано', [
                'currency_code' => $currencyCode,
            ]);

            return;
        }

        $oldRate = $currency->exchange_rate;

        $updateData = [
            'exchange_rate' => $rate,
        ];

        if ($officialRate !== null) {
            $updateData['official_rate'] = $officialRate;
        }

        if ($rateCoefficient !== null) {
            $updateData['rate_coefficient'] = $rateCoefficient;
        }

        if ($date) {
            $updateData['exchange_rate_date'] = $date;
        }

        $currency->update($updateData);

        Log::info('exchange_rate.updated: курс валюты обновлён', [
            'currency_id'        => $currency->id,
            'currency_code'      => $currencyCode,
            'old_rate'           => $oldRate,
            'new_rate'           => $rate,
            'official_rate'      => $officialRate,
            'rate_coefficient'   => $rateCoefficient,
            'date'               => $date,
        ]);
    }
}

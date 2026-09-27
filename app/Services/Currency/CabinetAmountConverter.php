<?php

namespace App\Services\Currency;

use App\Models\Currency;
use App\Models\User;
use App\Services\CurrencyService;

/**
 * Пересчёт сумм документов в валюту кабинета клиента.
 *
 * Заказы и реализации хранятся в валюте документа (как в 1С), а клиенту в
 * кабинете показываются ещё и в валюте его региона. Путь всегда один: валюта
 * документа → RUB по курсу источника → валюта клиента. Логика жила копиями в
 * контроллерах заказов и реализаций и разошлась бы на первой правке.
 */
class CabinetAmountConverter
{
    /** @var array<string, Currency|null> */
    private array $currencies = [];

    public function __construct(private readonly CurrencyService $currencyService) {}

    /**
     * Валюта кабинета — валюта региона клиента.
     */
    public function currencyOf(?User $user): ?Currency
    {
        return $user?->region?->currency;
    }

    /**
     * Сумма документа в валюте клиента. Без целевой валюты (или когда она базовая)
     * иностранный документ приводится к RUB, рублёвый возвращается как есть.
     */
    public function convert(float $amount, ?string $sourceCurrencyCode, ?Currency $targetCurrency): float
    {
        $amountInRub = $amount;

        if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
            $source = $this->currency($sourceCurrencyCode);

            if ($source) {
                $amountInRub = round($amount * (float) $source->exchange_rate, 2);
            }
        }

        if (! $targetCurrency || $targetCurrency->is_base) {
            return $amountInRub;
        }

        return $this->currencyService->convertFromBase($amountInRub, $targetCurrency);
    }

    private function currency(string $code): ?Currency
    {
        if (! array_key_exists($code, $this->currencies)) {
            $this->currencies[$code] = Currency::where('code', $code)->first();
        }

        return $this->currencies[$code];
    }
}

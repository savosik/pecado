<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

class HandleCostUpdated
{
    /**
     * Валюта, в которой сайт хранит себестоимость.
     *
     * Пересчёт по курсу не делается сознательно: курс меняется, и себестоимость
     * поехала бы задним числом вместе с ним.
     */
    private const BASE_CURRENCY = 'RUB';

    /**
     * Обработка события cost.updated из 1С (US-18, v15.13.0).
     *
     * Находит товар по product_uuid (external_id) и обновляет себестоимость.
     * Если товар не найден — событие игнорируется без ошибки.
     */
    public function handle(array $payload): void
    {
        $productUuid = $payload['product_uuid'] ?? null;
        $cost = $payload['cost'] ?? null;

        if (! $productUuid || $cost === null) {
            Log::warning('cost.updated: отсутствует product_uuid или cost', ['payload' => $payload]);

            return;
        }

        $currency = $payload['currency_code'] ?? null;

        if ($currency !== null && strtoupper((string) $currency) !== self::BASE_CURRENCY) {
            Log::warning('cost.updated: себестоимость не в рублях, событие проигнорировано', [
                'product_uuid' => $productUuid,
                'currency_code' => $currency,
            ]);

            return;
        }

        // withoutGlobalScopes: HiddenScope прячет снятые с публикации товары,
        // но себестоимость нужна и по ним — из них состоят прошлые отгрузки.
        $product = Product::withoutGlobalScopes()->where('external_id', $productUuid)->first();

        if (! $product) {
            Log::info('cost.updated: товар не найден по UUID, событие проигнорировано', [
                'product_uuid' => $productUuid,
            ]);

            return;
        }

        $oldCost = $product->cost_price;

        // Не через update(): cost_price намеренно вне $fillable, чтобы поле нельзя
        // было выставить массовым присваиванием из админской формы.
        $product->cost_price = $cost;
        $product->cost_price_updated_at = now();
        $product->save();

        Log::info('cost.updated: себестоимость товара обновлена', [
            'product_id' => $product->id,
            'product_uuid' => $productUuid,
            'old_cost' => $oldCost,
            'new_cost' => $cost,
        ]);
    }
}

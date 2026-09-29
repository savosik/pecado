<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use App\Services\Erp\Support\PendingPrices;
use App\Services\Erp\Support\ScheduledPrices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandlePriceUpdated
{
    public function __construct(
        private PendingPrices $pending = new PendingPrices,
        private ScheduledPrices $scheduled = new ScheduledPrices,
    ) {}

    /**
     * Обработка события price.updated из 1С.
     *
     * Находит товар по product_uuid (external_id) и обновляет базовую цену.
     * Если карточки ещё нет (цена обогнала product.created) — цена откладывается
     * и применяется при создании карточки (v16.12.3).
     *
     * v16.13.0: цена с будущей effective_from откладывается по паре товар ×
     * документ и включается планировщиком в свой срок. Прошедшая или
     * отсутствующая дата — цена применяется сразу, как раньше.
     */
    public function handle(array $payload): void
    {
        $productUuid = $payload['product_uuid'] ?? null;
        $price = $payload['price'] ?? null;

        if (! $productUuid || $price === null) {
            Log::warning('price.updated: отсутствует product_uuid или price', ['payload' => $payload]);

            return;
        }

        $documentUuid = $payload['document_uuid'] ?? null;
        $effectiveFrom = isset($payload['effective_from']) ? Carbon::parse($payload['effective_from']) : null;

        if ($effectiveFrom !== null && $documentUuid !== null && $effectiveFrom->isFuture()) {
            $this->scheduled->schedule(
                $productUuid,
                $documentUuid,
                (float) $price,
                $effectiveFrom,
                $payload['document_number'] ?? null,
                $payload['message_id'] ?? null,
            );

            Log::info('price.updated: цена отложена до даты вступления в силу', [
                'product_uuid' => $productUuid,
                'document_uuid' => $documentUuid,
                'document_number' => $payload['document_number'] ?? null,
                'price' => $price,
                'effective_from' => $effectiveFrom->toIso8601String(),
            ]);

            return;
        }

        // Документ перепровели датой, которая уже наступила: прежняя
        // отложенная запись этой пары больше не нужна.
        if ($documentUuid !== null) {
            $this->scheduled->forget($productUuid, $documentUuid);
        }

        $applied = DB::transaction(function () use ($productUuid, $price): bool {
            $product = Product::withoutGlobalScopes()
                ->where('external_id', $productUuid)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                return false;
            }

            $oldPrice = $product->base_price;

            $this->scheduled->supersedeDue($productUuid);
            $this->pending->discard($productUuid);
            $product->update([
                'base_price' => $price,
            ]);

            Log::info('price.updated: цена товара обновлена', [
                'product_id' => $product->id,
                'product_uuid' => $productUuid,
                'old_price' => $oldPrice,
                'new_price' => $price,
            ]);

            return true;
        });

        if ($applied) {
            return;
        }

        $this->scheduled->supersedeDue($productUuid);
        $this->pending->park($productUuid, (float) $price, $payload['message_id'] ?? null);

        // Карточка могла появиться, пока цена откладывалась: product.created
        // тогда уже проверил отложенные цены и нашей не увидел.
        if ($this->pending->apply($productUuid) === null) {
            Log::info('price.updated: товар не найден по UUID, цена отложена до product.created', [
                'product_uuid' => $productUuid,
                'price' => $price,
            ]);
        }
    }
}

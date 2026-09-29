<?php

namespace App\Services\Erp\Handlers;

use App\Services\Erp\Support\ScheduledPrices;
use Illuminate\Support\Facades\Log;

class HandlePriceCancelled
{
    public function __construct(private ScheduledPrices $scheduled = new ScheduledPrices) {}

    /**
     * Обработка события price.cancelled из 1С (v16.13.0).
     *
     * Документ установки цен будущей датой распровели, удалили или перепровели
     * без части строк — снимаем его отложенные цены по перечисленным товарам.
     * Действующую цену не трогаем: если цена документа уже вступила в силу,
     * 1С присылает отдельный price.updated с ценой после отмены.
     */
    public function handle(array $payload): void
    {
        $documentUuid = $payload['document_uuid'] ?? null;
        $productUuids = array_values(array_filter((array) ($payload['product_uuids'] ?? []), 'is_string'));

        if (! $documentUuid || $productUuids === []) {
            Log::warning('price.cancelled: отсутствует document_uuid или product_uuids', ['payload' => $payload]);

            return;
        }

        $removed = $this->scheduled->cancel($documentUuid, $productUuids);

        Log::info('price.cancelled: отложенные цены документа сняты', [
            'document_uuid' => $documentUuid,
            'products' => count($productUuids),
            'removed' => $removed,
        ]);
    }
}

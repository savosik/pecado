<?php

namespace App\Services\Erp\Handlers;

use App\Jobs\ProcessIndividualPricesFile;
use Illuminate\Support\Facades\Log;

class HandleIndividualPricesReady
{
    /**
     * Обработка события individual_prices.ready из 1С.
     *
     * 1С загрузила CSV-файл с индивидуальными ценами одного партнёра в MinIO
     * и отправила уведомление через RabbitMQ.
     *
     * Формат события:
     *   file_url:      s3://prices-exchange/2026-03-27/partner_a1b2c3d4.csv
     *   upload_type:   delta | full
     *   partner_uuid:  UUID партнёра (контрагента)
     *   records_count: количество строк (опционально)
     */
    public function handle(array $payload): void
    {
        $fileUrl = $payload['file_url'] ?? null;
        $uploadType = $payload['upload_type'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;
        $recordsCount = $payload['records_count'] ?? 0;
        $timestamp = $payload['timestamp'] ?? null;

        if (! $fileUrl || ! $uploadType || ! $partnerUuid) {
            Log::warning('individual_prices.ready: отсутствует file_url, upload_type или partner_uuid', [
                'payload' => $payload,
            ]);

            return;
        }

        if (! in_array($uploadType, ['full', 'delta'])) {
            Log::warning('individual_prices.ready: неизвестный upload_type', [
                'upload_type' => $uploadType,
                'payload' => $payload,
            ]);

            return;
        }

        Log::info('individual_prices.ready: получено уведомление, запуск обработки', [
            'file_url' => $fileUrl,
            'upload_type' => $uploadType,
            'partner_uuid' => $partnerUuid,
            'records_count' => $recordsCount,
            'timestamp' => $timestamp,
        ]);

        ProcessIndividualPricesFile::dispatch($fileUrl, $uploadType, $partnerUuid, $recordsCount);
    }
}

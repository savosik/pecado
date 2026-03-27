<?php

namespace App\Services\Erp\Handlers;

use App\Jobs\ProcessIndividualPricesFile;
use Illuminate\Support\Facades\Log;

class HandleIndividualPricesReady
{
    /**
     * Обработка события individual_prices.ready из 1С.
     *
     * 1С загрузила JSONL-файл с индивидуальными ценами в MinIO
     * и отправила уведомление через RabbitMQ.
     *
     * Диспатчим асинхронную Job для скачивания и обработки файла.
     */
    public function handle(array $payload): void
    {
        $fileUrl = $payload['file_url'] ?? null;
        $uploadType = $payload['upload_type'] ?? null;
        $recordsCount = $payload['records_count'] ?? 0;
        $timestamp = $payload['timestamp'] ?? null;

        if (!$fileUrl || !$uploadType) {
            Log::warning('individual_prices.ready: отсутствует file_url или upload_type', [
                'payload' => $payload,
            ]);

            return;
        }

        if (!in_array($uploadType, ['full', 'delta'])) {
            Log::warning('individual_prices.ready: неизвестный upload_type', [
                'upload_type' => $uploadType,
                'payload' => $payload,
            ]);

            return;
        }

        Log::info('individual_prices.ready: получено уведомление, запуск обработки', [
            'file_url' => $fileUrl,
            'upload_type' => $uploadType,
            'records_count' => $recordsCount,
            'timestamp' => $timestamp,
        ]);

        ProcessIndividualPricesFile::dispatch($fileUrl, $uploadType, $recordsCount);
    }
}

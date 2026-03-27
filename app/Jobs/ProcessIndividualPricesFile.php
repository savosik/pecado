<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessIndividualPricesFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество строк в одном батче для INSERT.
     */
    private const BATCH_SIZE = 3000;

    /**
     * Максимальное время выполнения (минут).
     */
    public int $timeout = 1800; // 30 минут

    public int $tries = 3;

    public function __construct(
        public readonly string $fileUrl,
        public readonly string $uploadType,
        public readonly int $recordsCount = 0,
    ) {}

    /**
     * Скачать JSONL из MinIO, прочитать потоково, батч-вставить в individual_prices.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        Log::info('ProcessIndividualPricesFile: начало обработки', [
            'file_url' => $this->fileUrl,
            'upload_type' => $this->uploadType,
            'records_count' => $this->recordsCount,
        ]);

        try {
            $filePath = $this->extractFilePath($this->fileUrl);
            $disk = Storage::disk('prices-exchange');

            if (!$disk->exists($filePath)) {
                Log::error('ProcessIndividualPricesFile: файл не найден в MinIO', [
                    'file_path' => $filePath,
                ]);

                return;
            }

            if ($this->uploadType === 'full') {
                $this->processFullDump($disk, $filePath);
            } else {
                $this->processDelta($disk, $filePath);
            }

            $elapsed = round(microtime(true) - $startTime, 2);

            Log::info('ProcessIndividualPricesFile: обработка завершена', [
                'file_url' => $this->fileUrl,
                'upload_type' => $this->uploadType,
                'elapsed_seconds' => $elapsed,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessIndividualPricesFile: ошибка обработки', [
                'file_url' => $this->fileUrl,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Дельта-обновление: INSERT ... ON DUPLICATE KEY UPDATE.
     */
    private function processDelta($disk, string $filePath): void
    {
        $batch = [];
        $totalProcessed = 0;

        foreach ($this->readJsonlStream($disk, $filePath) as $record) {
            $batch[] = $record;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->upsertBatch($batch);
                $totalProcessed += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->upsertBatch($batch);
            $totalProcessed += count($batch);
        }

        Log::info('ProcessIndividualPricesFile: дельта обработана', [
            'total_processed' => $totalProcessed,
        ]);
    }

    /**
     * Полная замена: временная таблица + RENAME.
     */
    private function processFullDump($disk, string $filePath): void
    {
        $tempTable = 'individual_prices_tmp_' . time();

        // Создаём временную таблицу с такой же структурой
        DB::statement("CREATE TABLE `{$tempTable}` LIKE `individual_prices`");

        try {
            $batch = [];
            $totalProcessed = 0;

            foreach ($this->readJsonlStream($disk, $filePath) as $record) {
                $batch[] = $record;

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->insertBatchInto($tempTable, $batch);
                    $totalProcessed += count($batch);
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                $this->insertBatchInto($tempTable, $batch);
                $totalProcessed += count($batch);
            }

            // Атомарная замена таблицы
            $oldTable = 'individual_prices_old_' . time();
            DB::statement("RENAME TABLE `individual_prices` TO `{$oldTable}`, `{$tempTable}` TO `individual_prices`");
            DB::statement("DROP TABLE IF EXISTS `{$oldTable}`");

            Log::info('ProcessIndividualPricesFile: полная замена завершена', [
                'total_processed' => $totalProcessed,
            ]);
        } catch (\Exception $e) {
            DB::statement("DROP TABLE IF EXISTS `{$tempTable}`");
            throw $e;
        }
    }

    /**
     * Потоковое чтение JSONL файла из Storage (генератор).
     *
     * @return \Generator<array{partner_uuid: string, product_uuid: string, warehouse_uuid: string, price: float}>
     */
    private function readJsonlStream($disk, string $filePath): \Generator
    {
        $stream = $disk->readStream($filePath);

        if (!$stream) {
            throw new \RuntimeException("Не удалось открыть поток для файла: {$filePath}");
        }

        $lineNumber = 0;

        try {
            while (($line = fgets($stream)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('ProcessIndividualPricesFile: невалидный JSON на строке', [
                        'line_number' => $lineNumber,
                        'error' => json_last_error_msg(),
                    ]);
                    continue;
                }

                if (empty($record['partner_uuid']) || empty($record['product_uuid']) || empty($record['warehouse_uuid'])) {
                    continue;
                }

                yield [
                    'partner_uuid' => $record['partner_uuid'],
                    'product_uuid' => $record['product_uuid'],
                    'warehouse_uuid' => $record['warehouse_uuid'],
                    'price' => (float) ($record['price'] ?? 0),
                ];
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Upsert батч: INSERT ... ON DUPLICATE KEY UPDATE.
     */
    private function upsertBatch(array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $values = [];
        $bindings = [];

        foreach ($batch as $record) {
            $values[] = '(?, ?, ?, ?, NOW())';
            $bindings[] = $record['partner_uuid'];
            $bindings[] = $record['product_uuid'];
            $bindings[] = $record['warehouse_uuid'];
            $bindings[] = $record['price'];
        }

        $sql = 'INSERT INTO `individual_prices` (`partner_uuid`, `product_uuid`, `warehouse_uuid`, `price`, `updated_at`) VALUES '
            . implode(', ', $values)
            . ' ON DUPLICATE KEY UPDATE `price` = VALUES(`price`), `updated_at` = NOW()';

        DB::statement($sql, $bindings);
    }

    /**
     * Вставка батча в конкретную таблицу (для full dump во временную таблицу).
     */
    private function insertBatchInto(string $table, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $values = [];
        $bindings = [];

        foreach ($batch as $record) {
            $values[] = '(?, ?, ?, ?, NOW())';
            $bindings[] = $record['partner_uuid'];
            $bindings[] = $record['product_uuid'];
            $bindings[] = $record['warehouse_uuid'];
            $bindings[] = $record['price'];
        }

        $sql = "INSERT INTO `{$table}` (`partner_uuid`, `product_uuid`, `warehouse_uuid`, `price`, `updated_at`) VALUES "
            . implode(', ', $values);

        DB::statement($sql, $bindings);
    }

    /**
     * Извлечь относительный путь из s3:// URL.
     * s3://prices-exchange/2026-03-26/delta_14-05.jsonl → 2026-03-26/delta_14-05.jsonl
     */
    private function extractFilePath(string $fileUrl): string
    {
        // Формат: s3://bucket-name/path/to/file
        if (str_starts_with($fileUrl, 's3://')) {
            $withoutScheme = substr($fileUrl, 5); // prices-exchange/2026-03-26/delta.jsonl
            $slashPos = strpos($withoutScheme, '/');

            return $slashPos !== false ? substr($withoutScheme, $slashPos + 1) : $withoutScheme;
        }

        // Если уже относительный путь
        return $fileUrl;
    }
}

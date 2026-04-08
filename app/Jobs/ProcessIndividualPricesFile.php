<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
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
    private const BATCH_SIZE = 5000;

    /**
     * Максимальное время выполнения (секунд).
     */
    public int $timeout = 1800; // 30 минут

    public int $tries = 3;

    public function __construct(
        public readonly string $fileUrl,
        public readonly string $uploadType,
        public readonly string $partnerUuid,
        public readonly int $recordsCount = 0,
    ) {}

    /**
     * Скачать CSV из MinIO, резолвить UUID→INT, батч-вставить.
     *
     * Формат CSV (без заголовка):
     *   product_uuid,warehouse_uuid,price
     *
     * partner_uuid передаётся в параметрах Job (один файл = один партнёр).
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        Log::info('ProcessIndividualPricesFile: начало обработки', [
            'file_url' => $this->fileUrl,
            'upload_type' => $this->uploadType,
            'partner_uuid' => $this->partnerUuid,
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

            // Резолвим partner_uuid → partner_id
            $partnerId = User::where('erp_id', $this->partnerUuid)->value('id');

            if (!$partnerId) {
                Log::warning('ProcessIndividualPricesFile: партнёр не найден', [
                    'partner_uuid' => $this->partnerUuid,
                ]);

                return;
            }

            // Загружаем маппинг UUID→INT для товаров и складов
            $maps = $this->loadMaps();

            $this->processFile($disk, $filePath, $partnerId, $maps);

            // Удаляем CSV из MinIO после успешной обработки
            $disk->delete($filePath);

            $elapsed = round(microtime(true) - $startTime, 2);

            Log::info('ProcessIndividualPricesFile: обработка завершена', [
                'file_url' => $this->fileUrl,
                'upload_type' => $this->uploadType,
                'partner_uuid' => $this->partnerUuid,
                'elapsed_seconds' => $elapsed,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessIndividualPricesFile: ошибка обработки', [
                'file_url' => $this->fileUrl,
                'partner_uuid' => $this->partnerUuid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Загрузить маппинг UUID → INT для товаров и складов.
     *
     * @return array{products: array<string, int>, warehouses: array<string, int>}
     */
    private function loadMaps(): array
    {
        $products = Product::whereNotNull('external_id')
            ->pluck('id', 'external_id')
            ->toArray();

        $warehouses = Warehouse::whereNotNull('external_id')
            ->pluck('id', 'external_id')
            ->toArray();

        Log::info('ProcessIndividualPricesFile: маппинг загружен', [
            'products' => count($products),
            'warehouses' => count($warehouses),
        ]);

        return compact('products', 'warehouses');
    }

    /**
     * Маршрутизация обработки по типу выгрузки (v9).
     *
     * - full:  DELETE все цены партнёра → INSERT батчами
     * - delta: UPSERT (INSERT ... ON DUPLICATE KEY UPDATE) только пришедших цен
     */
    private function processFile($disk, string $filePath, int $partnerId, array $maps): void
    {
        if ($this->uploadType === 'full') {
            $this->processFullFile($disk, $filePath, $partnerId, $maps);
        } else {
            $this->processDeltaFile($disk, $filePath, $partnerId, $maps);
        }
    }

    /**
     * Full: полное удаление всех цен партнёра → вставка батчами.
     * Один файл = полный прайс-лист одного партнёра.
     */
    private function processFullFile($disk, string $filePath, int $partnerId, array $maps): void
    {
        $batch = [];
        $totalProcessed = 0;
        $skipped = 0;

        foreach ($this->readCsvStream($disk, $filePath, $maps) as $record) {
            if ($record === null) {
                $skipped++;
                continue;
            }

            $batch[] = $record;

            if (count($batch) >= self::BATCH_SIZE) {
                // Первый батч — удаляем старые цены партнёра
                if ($totalProcessed === 0) {
                    DB::table('individual_prices')
                        ->where('partner_id', $partnerId)
                        ->delete();
                }

                $this->insertBatch($partnerId, $batch);
                $totalProcessed += count($batch);
                $batch = [];
            }
        }

        // Если первый батч ещё не начался — удаляем старые цены
        if ($totalProcessed === 0) {
            DB::table('individual_prices')
                ->where('partner_id', $partnerId)
                ->delete();
        }

        if (!empty($batch)) {
            $this->insertBatch($partnerId, $batch);
            $totalProcessed += count($batch);
        }

        Log::info('ProcessIndividualPricesFile [full]: файл обработан', [
            'partner_id' => $partnerId,
            'total_processed' => $totalProcessed,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Delta (v9): UPSERT только пришедших цен.
     * Остальные цены партнёра не удаляются.
     * INSERT ... ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = NOW()
     */
    private function processDeltaFile($disk, string $filePath, int $partnerId, array $maps): void
    {
        $batch = [];
        $totalProcessed = 0;
        $skipped = 0;

        foreach ($this->readCsvStream($disk, $filePath, $maps) as $record) {
            if ($record === null) {
                $skipped++;
                continue;
            }

            $batch[] = $record;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->upsertBatch($partnerId, $batch);
                $totalProcessed += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->upsertBatch($partnerId, $batch);
            $totalProcessed += count($batch);
        }

        Log::info('ProcessIndividualPricesFile [delta]: файл обработан', [
            'partner_id' => $partnerId,
            'total_processed' => $totalProcessed,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Потоковое чтение CSV файла из Storage (генератор).
     * Резолвит UUID→INT на лету.
     *
     * Формат CSV (без заголовка):
     *   product_uuid,warehouse_uuid,price
     *
     * @return \Generator<array{product_id: int, warehouse_id: int, price: float}|null>
     */
    private function readCsvStream($disk, string $filePath, array $maps): \Generator
    {
        $stream = $disk->readStream($filePath);

        if (!$stream) {
            throw new \RuntimeException("Не удалось открыть поток для файла: {$filePath}");
        }

        $lineNumber = 0;

        try {
            while (($row = fgetcsv($stream)) !== false) {
                $lineNumber++;

                // Пропускаем пустые строки
                if (count($row) < 3 || empty($row[0])) {
                    continue;
                }

                $productUuid = trim($row[0]);
                // Убираем UTF-8 BOM из первой строки (1С добавляет BOM)
                if ($lineNumber === 1) {
                    $productUuid = preg_replace('/^\x{FEFF}/u', '', $productUuid);
                }
                $warehouseUuid = trim($row[1]);
                $price = (float) trim($row[2]);

                // UUID → INT lookup
                $productId = $maps['products'][$productUuid] ?? null;
                $warehouseId = $maps['warehouses'][$warehouseUuid] ?? null;

                if (!$productId || !$warehouseId) {
                    yield null;
                    continue;
                }

                yield [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'price' => $price,
                ];
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Вставка батча для конкретного партнёра.
     */
    private function insertBatch(int $partnerId, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $values = [];

        foreach ($batch as $record) {
            $values[] = "({$partnerId},{$record['product_id']},{$record['warehouse_id']},{$record['price']})";
        }

        $sql = 'INSERT INTO `individual_prices` (`partner_id`, `product_id`, `warehouse_id`, `price`) VALUES '
            . implode(', ', $values);

        DB::statement($sql);
    }

    /**
     * UPSERT батча для дельта-обновления (v9).
     * INSERT ... ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = NOW()
     *
     * Обновляет только пришедшие цены, не трогая остальные.
     */
    private function upsertBatch(int $partnerId, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $values = [];

        foreach ($batch as $record) {
            $values[] = "({$partnerId},{$record['product_id']},{$record['warehouse_id']},{$record['price']},NOW())";
        }

        $sql = 'INSERT INTO `individual_prices` (`partner_id`, `product_id`, `warehouse_id`, `price`, `updated_at`) VALUES '
            . implode(', ', $values)
            . ' ON DUPLICATE KEY UPDATE `price` = VALUES(`price`), `updated_at` = NOW()';

        DB::statement($sql);
    }

    /**
     * Извлечь относительный путь из s3:// URL.
     * s3://prices-exchange/2026-03-26/partner_a1b2c3d4.csv → 2026-03-26/partner_a1b2c3d4.csv
     */
    private function extractFilePath(string $fileUrl): string
    {
        if (str_starts_with($fileUrl, 's3://')) {
            $withoutScheme = substr($fileUrl, 5);
            $slashPos = strpos($withoutScheme, '/');

            return $slashPos !== false ? substr($withoutScheme, $slashPos + 1) : $withoutScheme;
        }

        return $fileUrl;
    }
}

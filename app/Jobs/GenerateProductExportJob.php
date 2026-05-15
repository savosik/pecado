<?php

namespace App\Jobs;

use App\Models\ProductExport;
use App\Models\ProductExportRun;
use App\Services\ProductExport\ProductExportGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Асинхронная генерация файла стандартной пресетной выгрузки.
 *
 * Уникальность по product_export_id защищает от параллельных запусков:
 * пока джоба для конкретной выгрузки в очереди или выполняется, повторный
 * dispatch будет отклонён драйвером очереди (см. ShouldBeUnique). uniqueFor
 * чуть больше timeout — на случай зависания воркера.
 */
class GenerateProductExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Экспоненциальная задержка между ретраями: 30c → 2 мин → fail.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public int $timeout = 1500;

    public int $uniqueFor = 1800;

    /**
     * Порог в строках для разделения light/heavy очередей. Выгрузки с
     * estimated_rows < THRESHOLD уходят в `exports-light` (numprocs побольше,
     * короткий timeout), всё крупное — в `exports-heavy`. Если оценка ещё
     * не известна (первая генерация) — heavy, чтобы не блокировать light pool
     * долгим джобом.
     */
    private const HEAVY_THRESHOLD_ROWS = 1000;

    /**
     * Время dispatch'а в миллисекундах (Unix epoch). Заполняется в конструкторе,
     * сериализуется вместе с Job. В handle() считаем дельту до старта генерации —
     * это и есть «висел в очереди». Полезно понимать, упирается ли отдача
     * в backlog воркеров или в саму генерацию.
     */
    public int $dispatchedAtMs;

    public function __construct(public int $productExportId)
    {
        $this->onQueue($this->resolveQueueName($productExportId));
        $this->dispatchedAtMs = (int) round(microtime(true) * 1000);
    }

    /**
     * Выбирает очередь по estimated_rows. Запрос один-колончатый и идёт по
     * primary key — это копейки даже на горячем пути dispatch'а.
     */
    private function resolveQueueName(int $exportId): string
    {
        $estimated = ProductExport::where('id', $exportId)->value('estimated_rows');

        if ($estimated !== null && $estimated < self::HEAVY_THRESHOLD_ROWS) {
            return 'exports-light';
        }

        return 'exports-heavy';
    }

    public function uniqueId(): string
    {
        return "product-export:{$this->productExportId}";
    }

    public function handle(ProductExportGenerator $generator): void
    {
        $export = ProductExport::find($this->productExportId);
        if (! $export) {
            Log::warning('product_export.job.export_not_found', [
                'export_id' => $this->productExportId,
            ]);

            return;
        }

        $queuedForMs = max(0, (int) round(microtime(true) * 1000) - $this->dispatchedAtMs);

        $generator->generate($export, $queuedForMs);
    }

    public function failed(Throwable $e): void
    {
        $export = ProductExport::find($this->productExportId);
        if (! $export) {
            return;
        }

        // Если последний run ещё помечен generating (например, при таймауте без try/catch
        // в Generator) — закрываем его сюда вручную.
        $run = $export->lastRun;
        if ($run && $run->status === ProductExportRun::STATUS_GENERATING) {
            $run->update([
                'status' => ProductExportRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 5000),
            ]);
        }

        if ($export->status !== ProductExport::STATUS_READY) {
            $export->update(['status' => ProductExport::STATUS_FAILED]);
        }
    }
}

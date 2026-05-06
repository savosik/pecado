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

    public function __construct(public int $productExportId)
    {
        $this->onQueue('exports');
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

        $generator->generate($export);
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

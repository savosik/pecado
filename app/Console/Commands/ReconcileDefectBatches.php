<?php

namespace App\Console\Commands;

use App\Services\Defect\DefectShipmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая починка партий некондиции, «застрявших» в статусе «Распродана».
 *
 * До v15.9.2 отмена реализации возвращала партию в продажу не всегда: пересчёт
 * не срабатывал, если заказ уценки был удалён раньше реализации, если 1С
 * отменила документ статусом `cancelled`, или если сняли только заказ. Такие
 * партии остались закрытыми, хотя товар физически на складе. Команда
 * пересчитывает их по текущему состоянию документов.
 */
class ReconcileDefectBatches extends Command
{
    protected $signature = 'defects:reconcile {--dry-run : Только показать, что вернётся в продажу}';

    protected $description = 'Пересчитать закрытые как распроданные партии некондиции по актуальным реализациям';

    public function handle(DefectShipmentService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // На сухом прогоне считаем той же логикой и откатываем — чтобы отчёт
        // не разошёлся с тем, что сделает боевой запуск.
        if ($dryRun) {
            DB::beginTransaction();
        }

        $reopened = $service->reconcileClosedBatches();

        if ($dryRun) {
            DB::rollBack();
        }

        if ($reopened === []) {
            $this->info('Все распроданные партии закрыты корректно, изменений нет.');

            return self::SUCCESS;
        }

        $this->info(
            ($dryRun ? 'Вернётся в продажу партий: ' : 'Возвращено в продажу партий: ')
            .count($reopened).' ('.implode(', ', $reopened).')'
        );

        return self::SUCCESS;
    }
}

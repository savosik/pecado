<?php

namespace App\Console\Commands;

use App\Jobs\GenerateProductExportJob;
use App\Models\ProductExport;
use App\Services\ProductExport\ProductExportDataVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Превентивная регенерация кэша стандартных пресетных выгрузок.
 *
 * Партнёры скачивают свои фиды по расписанию (некоторые — раз в час).
 * Если кэш «остыл» к моменту скачивания, GET /export/{hash} вернёт stale-копию
 * и поставит регенерацию в очередь — клиент получит свежие данные только
 * со следующего захода. Эта команда заранее ставит в очередь регенерацию
 * для активных партнёрских выгрузок, чтобы запросы попадали в свежий кэш.
 *
 * GenerateProductExportJob уникален по export_id (ShouldBeUnique),
 * поэтому повторный запуск warm не создаст дублей в очереди.
 */
class WarmupProductExports extends Command
{
    protected $signature = 'exports:warm
        {--days=7 : прогревать только выгрузки, скачивавшиеся за последние N дней}
        {--limit=200 : ограничение по числу выгрузок за один запуск (0 = без лимита). Дефолт 200 защищает от затопления очереди при росте числа активных партнёров; на ручной разовый прогрев передавайте --limit=0}';

    protected $description = 'Прогрев кэша стандартных пресетных выгрузок: ставит в очередь GenerateProductExportJob для активных партнёрских выгрузок';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');

        $cutoff = now()->subDays(max(1, $days));

        // Текущая версия данных каталога. Если экспорт уже отражает её —
        // прогревать незачем. Если bump'ов ещё не было (свежий Redis / первый
        // запуск после PR4) — current.getTimestamp() == 0, все выгрузки попадут
        // под старое поведение (прогревать всё активное).
        $currentVersion = app(ProductExportDataVersion::class)->current();
        $hasVersion = $currentVersion->getTimestamp() > 0;

        $query = ProductExport::query()
            ->where('is_active', true)
            ->whereNotNull('preset')
            ->where('last_downloaded_at', '>=', $cutoff)
            ->whereNotIn('status', [
                ProductExport::STATUS_QUEUED,
                ProductExport::STATUS_GENERATING,
            ])
            ->when($hasVersion, function ($q) use ($currentVersion) {
                $q->where(function ($inner) use ($currentVersion) {
                    $inner->whereNull('data_version_at')
                        ->orWhere('data_version_at', '<', $currentVersion);
                });
            })
            ->orderBy('last_downloaded_at', 'desc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $dispatched = 0;
        $query->each(function (ProductExport $export) use (&$dispatched) {
            GenerateProductExportJob::dispatch($export->id);
            $dispatched++;
        });

        $this->info("exports:warm: поставлено в очередь {$dispatched} выгрузок (порог: {$days} дн., версия данных: ".($hasVersion ? $currentVersion->toDateTimeString() : 'не задана').')');

        Log::info('exports.warmup', [
            'dispatched' => $dispatched,
            'days_threshold' => $days,
            'has_data_version' => $hasVersion,
            'data_version_at' => $hasVersion ? $currentVersion->toIso8601String() : null,
        ]);

        return self::SUCCESS;
    }
}

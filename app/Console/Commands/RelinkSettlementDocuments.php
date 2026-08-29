<?php

namespace App\Console\Commands;

use App\Models\SettlementEntry;
use App\Services\Settlements\SettlementProjector;
use Illuminate\Console\Command;

/**
 * Доливка полиморфной связи «строка регистра → документ сайта» по document_uuid.
 *
 * Штатно связь ставит `SettlementProjector::projectDocument()` при каждом
 * `settlement.posted` / `payment_schedule.updated`, а для документа, приехавшего
 * позже движений, — `SettlementLinkObserver`. Но линковка внутри проекции появилась
 * только 25.08.2026 (`ba14c536`): стартовая загрузка регистра 13.08 и всё до релиза
 * легли без `document_id` — на проде ~11 тыс. строк по 7,8 тыс. документов. Без связи
 * в кабинете у строки графика нет ссылки «Открыть» на реализацию, хотя реализация
 * на сайте есть.
 *
 * Команда идемпотентна и дешёвая (только строки без связи), поэтому запускается
 * при деплое как страховочная сеть.
 */
class RelinkSettlementDocuments extends Command
{
    protected $signature = 'settlements:relink-documents
        {--chunk=500 : Размер пачки UUID}
        {--dry-run : Только посчитать, ничего не записывая}';

    protected $description = 'Привязать строки регистра без document_id к реализациям, заказам и платежам сайта по document_uuid';

    public function handle(SettlementProjector $projector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $uuids = SettlementEntry::query()
            ->whereNull('document_id')
            ->whereNotNull('document_uuid')
            ->distinct()
            ->pluck('document_uuid');

        if ($uuids->isEmpty()) {
            $this->info('Строк регистра без привязки к документу нет.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Документов без привязки: %d', $uuids->count()));

        $documents = 0;
        $rows = 0;
        $missing = 0;

        foreach ($uuids->chunk($chunk) as $batch) {
            foreach ($batch as $uuid) {
                if ($dryRun) {
                    $projector->hasDocument($uuid) ? $documents++ : $missing++;

                    continue;
                }

                $linked = $projector->linkDocument($uuid);

                if ($linked > 0) {
                    $documents++;
                    $rows += $linked;
                } else {
                    $missing++;
                }
            }
        }

        $this->info($dryRun
            ? sprintf('Прогон без записи: документ найден на сайте у %d, не найден у %d.', $documents, $missing)
            : sprintf('Готово: привязано %d строк по %d документам; документа на сайте нет у %d.', $rows, $documents, $missing));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Переподвязывает orphaned media-записи товаров к живым Product-ам по артикулу.
 *
 * Фон: модель Product может пересоздаваться при синхронизации с 1С — старая
 * media-запись продолжает ссылаться на удалённый products.id, а файл в MinIO
 * живёт по пути products-media/{code}/... благодаря ProductPathGenerator.
 * Эта команда обновляет model_id у таких записей на id текущего Product
 * с тем же артикулом. Коллекция `main` пропускается, если у живого товара
 * уже есть main — чтобы не плодить дубликаты (старый main удалится позже
 * через media-library:clean --delete-orphaned).
 */
class ReattachOrphanedMedia extends Command
{
    protected $signature = 'catalog:reattach-orphaned-media
        {--dry-run : Показать что будет переподвязано, без изменений в БД}';

    protected $description = 'Переподвязать orphaned media-записи товаров к живым Product по артикулу (custom_properties.product_code)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $morph = (new Product)->getMorphClass();

        $this->info('Сбор карт product_code → products.id и id с существующим main...');

        $codeToId = DB::table('products')
            ->whereNotNull('code')
            ->pluck('id', 'code')
            ->all();

        $liveIds = array_values($codeToId);
        $liveIdsFlipped = array_flip($liveIds);

        $productsWithMain = DB::table('media')
            ->where('model_type', $morph)
            ->where('collection_name', 'main')
            ->whereIn('model_id', $liveIds)
            ->pluck('model_id')
            ->all();
        $productsWithMainFlipped = array_flip($productsWithMain);

        $total = DB::table('media')
            ->where('model_type', $morph)
            ->whereNotIn('model_id', $liveIds)
            ->count();

        if ($total === 0) {
            $this->info('Orphaned media-записей для Product не найдено.');

            return self::SUCCESS;
        }

        $this->info("Orphaned media для Product: {$total}");

        if ($dryRun) {
            $this->warn('Режим dry-run: изменения в БД не будут применены');
        }

        $reattached = 0;
        $skippedNoCode = 0;
        $skippedNoLiveProduct = 0;
        $skippedDuplicateMain = 0;
        $codesGainingMain = [];

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% / %estimated:-6s% %message%');
        $bar->setMessage('');
        $bar->start();

        DB::table('media')
            ->where('model_type', $morph)
            ->whereNotIn('model_id', $liveIds)
            ->select('id', 'collection_name', 'custom_properties')
            ->orderBy('id')
            ->chunkById(2000, function ($chunk) use (
                $codeToId,
                $productsWithMainFlipped,
                $dryRun,
                &$reattached,
                &$skippedNoCode,
                &$skippedNoLiveProduct,
                &$skippedDuplicateMain,
                &$codesGainingMain,
                $bar,
            ) {
                foreach ($chunk as $row) {
                    $bar->advance();

                    $props = json_decode($row->custom_properties, true);
                    $code = is_array($props) ? ($props['product_code'] ?? null) : null;

                    if (! $code) {
                        $skippedNoCode++;

                        continue;
                    }

                    $liveId = $codeToId[$code] ?? null;
                    if (! $liveId) {
                        $skippedNoLiveProduct++;

                        continue;
                    }

                    if ($row->collection_name === 'main' && isset($productsWithMainFlipped[$liveId])) {
                        $skippedDuplicateMain++;

                        continue;
                    }

                    if ($row->collection_name === 'main') {
                        $codesGainingMain[$code] = true;
                        // помечаем, чтобы последующие orphaned main того же code уходили в skip
                        $productsWithMainFlipped[$liveId] = true;
                    }

                    if (! $dryRun) {
                        DB::table('media')
                            ->where('id', $row->id)
                            ->update(['model_id' => $liveId]);
                    }

                    $reattached++;
                }
            });

        $bar->finish();
        $this->newLine(2);

        $gainedMain = count($codesGainingMain);

        $this->info('═══════════════════════════════════════');
        $this->info('  Перепривязка orphaned media');
        $this->info('═══════════════════════════════════════');
        $this->line("  Всего orphaned:              {$total}");
        $this->line("  Перепривязано:               {$reattached}");
        $this->line("  Товаров, получивших main:    {$gainedMain}");
        $this->line("  Пропущено (нет product_code):     {$skippedNoCode}");
        $this->line("  Пропущено (нет live Product):     {$skippedNoLiveProduct}");
        $this->line("  Пропущено (дубль main):           {$skippedDuplicateMain}");

        if ($skippedNoLiveProduct + $skippedDuplicateMain > 0) {
            $this->newLine();
            $this->warn('  Оставшиеся orphaned можно удалить через media-library:clean --delete-orphaned');
        }

        $this->info('═══════════════════════════════════════');

        return self::SUCCESS;
    }
}

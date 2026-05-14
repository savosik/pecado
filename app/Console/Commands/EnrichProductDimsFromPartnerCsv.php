<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Импорт физических габаритов товара из партнёрской CSV-выгрузки.
 *
 * Колонки Vakulov / sex-opt:
 *   width_packed, height_packed, length_packed (см) → products.width/height/depth (м)
 *   weight_packed (кг)                            → products.weight_gross (кг)
 *
 * Семантика: габариты у нас принадлежат конкретному Product (а не Model),
 * потому что варианты одной модели (разные размеры/цвета) реально имеют
 * разную упаковку — в БД это видно на 494 моделях с разной шириной у
 * вариантов и 1051 модели с разным весом.
 *
 * По умолчанию НЕ перетирает существующие непустые значения: обновляем
 * только NULL и 0, чтобы не затирать данные из 1С реальными вместо тех,
 * что пришли от партнёра. Флаг --force позволяет принудительно синхронизировать.
 *
 * Пример:
 *   php artisan partner-export:enrich-product-dims tmp/old_exports/vakulov.csv
 */
class EnrichProductDimsFromPartnerCsv extends Command
{
    protected $signature = 'partner-export:enrich-product-dims
        {path : Путь к CSV-файлу (UTF-8, разделитель ;)}
        {--code-column=code : Имя колонки с product code в CSV}
        {--width-column=width_packed : Имя CSV-колонки → products.width (см)}
        {--height-column=height_packed : Имя CSV-колонки → products.height (см)}
        {--depth-column=length_packed : Имя CSV-колонки → products.depth (см)}
        {--weight-column=weight_packed : Имя CSV-колонки → products.weight_gross (кг)}
        {--delimiter=; : Разделитель CSV}
        {--force : Перетирать непустые значения (по умолчанию не трогаем)}
        {--dry-run : Только показать сводку, ничего не писать}';

    protected $description = 'Импортирует width/height/depth/weight_gross из партнёрского CSV';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $delimiter = $this->option('delimiter');
        $codeColumn = $this->option('code-column');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $mapping = [
            'width' => $this->option('width-column'),    // см → м
            'height' => $this->option('height-column'),  // см → м
            'depth' => $this->option('depth-column'),    // см → м
            'weight_gross' => $this->option('weight-column'), // кг
        ];

        $handle = fopen($path, 'r');
        $headerRow = fgetcsv($handle, 0, $delimiter);
        if (! $headerRow) {
            fclose($handle);
            $this->error('Пустой CSV');

            return self::FAILURE;
        }
        $headerRow[0] = ltrim($headerRow[0], "\xEF\xBB\xBF");
        $columnIndex = array_flip($headerRow);

        foreach (array_merge([$codeColumn], array_values($mapping)) as $col) {
            if (! isset($columnIndex[$col])) {
                fclose($handle);
                $this->error("Колонка «{$col}» не найдена в CSV");

                return self::FAILURE;
            }
        }

        $this->info('Маппинг (см→м для размеров, кг как есть для weight_gross):');
        foreach ($mapping as $dbCol => $csvCol) {
            $this->line("  CSV «{$csvCol}» → products.{$dbCol}");
        }
        $this->info($force ? 'Режим: --force (перетираем непустые)' : 'Режим: только пустые/0 (безопасный)');

        $codeIdx = $columnIndex[$codeColumn];
        $stats = [
            'rows' => 0,
            'matched' => 0,
            'no_product' => 0,
            'updated_products' => 0,
            'fields_set' => 0,
            'fields_skipped_non_empty' => 0,
        ];

        $bar = $this->output->createProgressBar();
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $stats['rows']++;
            $code = $row[$codeIdx] ?? null;
            if (! $code) {
                continue;
            }

            $product = DB::table('products')->where('code', $code)->first(['id', 'width', 'height', 'depth', 'weight_gross']);
            if (! $product) {
                $stats['no_product']++;

                continue;
            }
            $stats['matched']++;

            $updates = [];
            foreach ($mapping as $dbCol => $csvCol) {
                $rawValue = $row[$columnIndex[$csvCol]] ?? null;
                if ($rawValue === null || $rawValue === '' || ! is_numeric($rawValue)) {
                    continue;
                }
                $value = (float) $rawValue;
                if ($value <= 0) {
                    continue;
                }

                // Размеры из см в м; вес уже в кг.
                if ($dbCol !== 'weight_gross') {
                    $value = round($value / 100, 4);
                }

                $existing = (float) ($product->{$dbCol} ?? 0);
                if (! $force && $existing > 0) {
                    $stats['fields_skipped_non_empty']++;

                    continue;
                }

                $updates[$dbCol] = $value;
                $stats['fields_set']++;
            }

            if ($updates && ! $dryRun) {
                DB::table('products')->where('id', $product->id)->update($updates + ['updated_at' => now()]);
                $stats['updated_products']++;
            } elseif ($updates) {
                $stats['updated_products']++;
            }

            if ($stats['rows'] % 200 === 0) {
                $bar->advance(200);
            }
        }
        $bar->finish();
        fclose($handle);

        $this->newLine(2);
        $this->info($dryRun ? 'Dry-run завершён.' : 'Готово.');
        $this->table(['Метрика', 'Значение'], [
            ['Прочитано строк', $stats['rows']],
            ['Найдено товаров по code', $stats['matched']],
            ['Без совпадения по code', $stats['no_product']],
            ['Обновлено товаров', $stats['updated_products']],
            ['Установлено полей', $stats['fields_set']],
            ['Пропущено (не пустое)', $stats['fields_skipped_non_empty']],
        ]);

        return self::SUCCESS;
    }
}

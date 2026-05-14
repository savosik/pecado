<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Однократное обогащение товаров атрибутами из CSV-выгрузки партнёра.
 *
 * Ситуация: у партнёра (sex-opt) в выгрузке есть колонки (group_code,
 * group_title, brand_code, retail_price, embed3d), которых нет в нашей модели
 * — они тащатся из 1С в их базу. Чтобы не плодить сущности и не делать новые
 * миграции под каждую такую колонку, данные кладутся в существующий механизм
 * атрибутов (Attribute / AttributeValue), у атрибутов выставлен флаг
 * `show_in_export=true, show_on_site=false` — они не светятся в каталоге.
 *
 * Маппинг указывается JSON-строкой: `{"csv_column": "attribute_slug", ...}`.
 * Соответствие товаров — по колонке code из CSV ↔ products.code.
 *
 * Пример:
 *   php artisan partner-export:enrich-from-csv tmp/old_exports/vakulov.csv \
 *       --mapping='{"group_code":"partner_group_code","brand_code":"partner_brand_code"}'
 *
 * Идемпотентно: повторный запуск перезаписывает значения; товар без code
 * не находится — пропускается со счётчиком.
 */
class EnrichProductsFromPartnerCsv extends Command
{
    protected $signature = 'partner-export:enrich-from-csv
        {path : Путь к CSV-файлу (UTF-8, разделитель ;)}
        {--mapping= : JSON-строка "csv_column":"attribute_slug"}
        {--code-column=code : Имя колонки в CSV, по которой ищем Product.code}
        {--delimiter=; : Разделитель CSV}
        {--dry-run : Не записывать в БД, только показать сводку}';

    protected $description = 'Обогащает товары атрибутами из CSV-выгрузки партнёра';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $mappingJson = $this->option('mapping');
        if (! $mappingJson) {
            $this->error('--mapping обязателен. Пример: --mapping=\'{"group_code":"partner_group_code"}\'');

            return self::FAILURE;
        }

        $mapping = json_decode($mappingJson, true);
        if (! is_array($mapping) || empty($mapping)) {
            $this->error('--mapping должен быть JSON-объектом column→slug');

            return self::FAILURE;
        }

        $codeColumn = $this->option('code-column');
        $delimiter = $this->option('delimiter');
        $dryRun = (bool) $this->option('dry-run');

        // Резолвим атрибуты по slug
        $attributesBySlug = Attribute::whereIn('slug', array_values($mapping))->get()->keyBy('slug');

        $missing = array_diff(array_values($mapping), $attributesBySlug->keys()->all());
        if ($missing) {
            $this->error('Не найдены атрибуты со slug: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->info('Маппинг:');
        foreach ($mapping as $col => $slug) {
            $type = $attributesBySlug[$slug]->type;
            $this->line("  CSV «{$col}» → attribute «{$slug}» ({$type})");
        }

        $handle = fopen($path, 'r');
        if (! $handle) {
            $this->error("Не удалось открыть файл: {$path}");

            return self::FAILURE;
        }

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if (! $headerRow) {
            fclose($handle);
            $this->error('Файл пустой');

            return self::FAILURE;
        }
        // Снимаем UTF-8 BOM с первой ячейки если есть
        $headerRow[0] = ltrim($headerRow[0], "\xEF\xBB\xBF");

        $columnIndex = array_flip($headerRow);
        if (! isset($columnIndex[$codeColumn])) {
            fclose($handle);
            $this->error("Колонка «{$codeColumn}» не найдена в заголовке CSV");

            return self::FAILURE;
        }

        foreach (array_keys($mapping) as $col) {
            if (! isset($columnIndex[$col])) {
                fclose($handle);
                $this->error("Колонка «{$col}» не найдена в CSV");

                return self::FAILURE;
            }
        }

        $stats = [
            'rows' => 0,
            'matched' => 0,
            'no_product' => 0,
            'attr_values_set' => 0,
        ];

        $codeIdx = $columnIndex[$codeColumn];
        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current% rows  matched: %matched%');
        $bar->setMessage('0', 'matched');

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $stats['rows']++;
            $code = $row[$codeIdx] ?? null;
            if (! $code) {
                continue;
            }

            $productId = DB::table('products')->where('code', $code)->value('id');
            if (! $productId) {
                $stats['no_product']++;

                continue;
            }
            $stats['matched']++;
            $bar->setMessage((string) $stats['matched'], 'matched');

            if (! $dryRun) {
                foreach ($mapping as $col => $slug) {
                    $value = $row[$columnIndex[$col]] ?? null;
                    if ($value === null || $value === '') {
                        // Пустое значение — не создаём строку (но и не удаляем существующую)
                        continue;
                    }
                    $this->upsertAttributeValue(
                        $productId,
                        $attributesBySlug[$slug],
                        $value,
                    );
                    $stats['attr_values_set']++;
                }
            }

            $bar->advance();
            if ($stats['rows'] % 500 === 0) {
                $bar->display();
            }
        }
        $bar->finish();
        fclose($handle);

        $this->newLine(2);
        $this->info('Готово.');
        $this->table(['Метрика', 'Значение'], [
            ['Прочитано строк', $stats['rows']],
            ['Найдено товаров по code', $stats['matched']],
            ['Без совпадения по code', $stats['no_product']],
            ['Установлено attribute values', $dryRun ? '(dry-run)' : $stats['attr_values_set']],
        ]);

        return self::SUCCESS;
    }

    /**
     * Создаёт или обновляет значение атрибута для товара. Учитывает тип:
     * `string` → text_value, `number` → number_value, `boolean` → boolean_value.
     * Для select-типов в этой команде не работаем — у партнёрских данных
     * нет фиксированного словаря, нужна была бы отдельная синхронизация
     * AttributeValue.
     */
    protected function upsertAttributeValue(int $productId, Attribute $attribute, string $value): void
    {
        $payload = [
            'product_id' => $productId,
            'attribute_id' => $attribute->id,
            'text_value' => null,
            'number_value' => null,
            'boolean_value' => null,
            'attribute_value_id' => null,
        ];

        if ($attribute->isNumber()) {
            $payload['number_value'] = is_numeric($value) ? (float) $value : null;
            if ($payload['number_value'] === null) {
                return;
            }
        } elseif ($attribute->isBoolean()) {
            $payload['boolean_value'] = in_array(strtolower($value), ['1', 'true', 'да', 'yes'], true);
        } else {
            $payload['text_value'] = $value;
        }

        DB::table('product_attribute_values')->updateOrInsert(
            ['product_id' => $productId, 'attribute_id' => $attribute->id],
            $payload + ['updated_at' => now(), 'created_at' => now()],
        );
    }
}

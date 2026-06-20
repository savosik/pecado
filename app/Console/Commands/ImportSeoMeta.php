<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportSeoMeta extends Command
{
    protected $signature = 'seo:import-meta
        {--wave= : Импортировать только строки указанной волны (1, 2, 3 или "-" для нишевых)}
        {--dry-run : Ничего не писать в БД, показать что изменится}
        {--file= : Путь к CSV (по умолчанию seo/meta_tags_pecado.csv)}';

    protected $description = 'Импорт SEO-меты (meta_title, meta_description, meta_keywords) категориям и брендам из CSV по слагу';

    public function handle(): int
    {
        $file = $this->option('file') ?: base_path('seo/meta_tags_pecado.csv');
        $wave = $this->option('wave');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("CSV не найден или недоступен для чтения: {$file}");

            return self::FAILURE;
        }

        $this->info("Файл: {$file}");
        $this->info($dryRun ? 'Режим: DRY-RUN — изменения не сохраняются.' : 'Режим: запись в БД (CSV — источник правды, мета перезаписывается).');
        if ($wave !== null) {
            $this->info("Фильтр по волне: {$wave}");
        }

        $rows = $this->readCsv($file);

        if ($rows === null) {
            $this->error('Не удалось прочитать заголовок CSV.');

            return self::FAILURE;
        }

        $updated = 0;
        $unchanged = 0;
        $notFound = 0;
        $skippedMain = 0;
        $h1Mismatches = [];
        $notFoundSlugs = [];
        $tableRows = [];

        foreach ($rows as $row) {
            $url = trim($row['url'] ?? '');
            if ($url === '') {
                continue;
            }

            if ($wave !== null && $this->normalizeWave($row['волна'] ?? '') !== $this->normalizeWave($wave)) {
                continue;
            }

            // Главная страница — мета задаётся в HomeController, не через эту команду.
            if ($url === '/') {
                $skippedMain++;
                $tableRows[] = [$url, '— главная (пропуск)', ''];

                continue;
            }

            [$type, $slug] = $this->parseUrl($url);

            if ($type === null) {
                $tableRows[] = [$url, 'неизвестный тип URL', ''];

                continue;
            }

            $model = $type === 'category'
                ? Category::where('slug', $slug)->first()
                : Brand::where('slug', $slug)->first();

            if ($model === null) {
                $notFound++;
                $notFoundSlugs[] = $url;
                $tableRows[] = [$url, 'НЕ НАЙДЕНО', ''];

                continue;
            }

            // H1 не перетираем (у категорий/брендов H1 = name); только логируем расхождение.
            $csvH1 = trim($row['H1'] ?? '');
            if ($csvH1 !== '' && $csvH1 !== trim((string) $model->name)) {
                $h1Mismatches[] = "{$url}: H1 CSV «{$csvH1}» ≠ name «{$model->name}»";
            }

            $changes = $this->diffChanges($model, [
                'meta_title' => trim($row['Title'] ?? ''),
                'meta_description' => trim($row['Description'] ?? ''),
                'meta_keywords' => trim($row['Keywords'] ?? ''),
            ]);

            if (empty($changes)) {
                $unchanged++;
                $tableRows[] = [$url, 'без изменений', ''];

                continue;
            }

            if (! $dryRun) {
                $model->fill($changes);
                $model->saveQuietly();
            }

            $updated++;
            $tableRows[] = [$url, $dryRun ? 'будет обновлено' : 'обновлено', implode(', ', array_keys($changes))];
        }

        $this->newLine();
        $this->table(['URL', 'Статус', 'Поля'], $tableRows);
        $this->newLine();

        $this->info(($dryRun ? 'Будет обновлено' : 'Обновлено').": {$updated}");
        $this->line("Без изменений (идемпотентно): {$unchanged}");
        $this->line("Пропущено (главная): {$skippedMain}");

        if ($notFound > 0) {
            $this->warn("Не найдено по slug: {$notFound}");
            foreach ($notFoundSlugs as $u) {
                $this->line("  • {$u}");
            }
            Log::warning('seo:import-meta — не найдены slug', $notFoundSlugs);
        } else {
            $this->info('Не найденных slug: 0');
        }

        if (! empty($h1Mismatches)) {
            $this->warn('Расхождения H1 ≠ name (мета обновлена, H1 не тронут):');
            foreach ($h1Mismatches as $m) {
                $this->line("  • {$m}");
            }
            Log::info('seo:import-meta — расхождения H1', $h1Mismatches);
        }

        return self::SUCCESS;
    }

    /**
     * Прочитать CSV (разделитель «;») в массив ассоциативных строк по заголовку.
     *
     * @return array<int, array<string, string>>|null
     */
    private function readCsv(string $file): ?array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return null;
        }

        $header = fgetcsv($handle, 0, ';');
        if ($header === false) {
            fclose($handle);

            return null;
        }
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            // fgetcsv на пустой строке возвращает [null].
            if ($data === [null]) {
                continue;
            }
            $assoc = [];
            foreach ($header as $i => $col) {
                $assoc[$col] = $data[$i] ?? '';
            }
            $rows[] = $assoc;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Распарсить URL в [тип, slug]. Возвращает [null, null] для неизвестного формата.
     *
     * @return array{0: 'category'|'brand'|null, 1: string|null}
     */
    private function parseUrl(string $url): array
    {
        $path = trim(parse_url($url, PHP_URL_PATH) ?: $url, '/');

        if (str_starts_with($path, 'categories/')) {
            return ['category', substr($path, strlen('categories/'))];
        }

        if (str_starts_with($path, 'brands/')) {
            return ['brand', substr($path, strlen('brands/'))];
        }

        return [null, null];
    }

    /**
     * Вернуть только реально меняющиеся поля (идемпотентность).
     *
     * @param  array<string, string>  $incoming
     * @return array<string, string>
     */
    private function diffChanges(Category|Brand $model, array $incoming): array
    {
        $changes = [];
        foreach ($incoming as $field => $value) {
            if ($value === '') {
                continue;
            }
            if ((string) ($model->{$field} ?? '') !== $value) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    private function normalizeWave(string $wave): string
    {
        $wave = trim($wave);

        // «—», «-», пусто трактуем одинаково (нишевые страницы без волны).
        return in_array($wave, ['—', '-', ''], true) ? '-' : $wave;
    }
}

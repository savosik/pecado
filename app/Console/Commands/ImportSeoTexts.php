<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportSeoTexts extends Command
{
    protected $signature = 'seo:import-texts
        {--dir= : Каталог с HTML-файлами (по умолчанию seo/texts)}
        {--dry-run : Ничего не писать в БД, показать что изменится}';

    protected $description = 'Импорт тел SEO-текстов (HTML) в categories.description / brands.short_description по слагу из имени файла';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: base_path('seo/texts');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_dir($dir)) {
            $this->error("Каталог не найден: {$dir}");

            return self::FAILURE;
        }

        $files = glob(rtrim($dir, '/').'/*.html') ?: [];

        if (empty($files)) {
            $this->warn("Нет .html файлов в {$dir}");

            return self::SUCCESS;
        }

        $this->info("Каталог: {$dir} (файлов: ".count($files).')');
        $this->info($dryRun ? 'Режим: DRY-RUN — изменения не сохраняются.' : 'Режим: запись в БД.');

        $updated = 0;
        $unchanged = 0;
        $notFound = 0;
        $notFoundSlugs = [];
        $tableRows = [];

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $html = trim((string) file_get_contents($file));

            if ($html === '') {
                $tableRows[] = [$slug, 'пустой файл', ''];

                continue;
            }

            [$entity, $field, $type] = $this->resolveTarget($slug);

            if ($entity === null) {
                $notFound++;
                $notFoundSlugs[] = $slug;
                $tableRows[] = [$slug, 'НЕ НАЙДЕНО', ''];

                continue;
            }

            if ((string) $entity->{$field} === $html) {
                $unchanged++;
                $tableRows[] = [$slug, "{$type}: без изменений", $field];

                continue;
            }

            if (! $dryRun) {
                $entity->{$field} = $html;
                $entity->save();
            }

            $updated++;
            $tableRows[] = [$slug, $dryRun ? "{$type}: будет обновлено" : "{$type}: обновлено", $field];
        }

        $this->newLine();
        $this->table(['slug', 'Статус', 'Поле'], $tableRows);
        $this->newLine();

        $this->info(($dryRun ? 'Будет обновлено' : 'Обновлено').": {$updated}");
        $this->line("Без изменений (идемпотентно): {$unchanged}");

        if ($notFound > 0) {
            $this->warn("Не найдено по slug: {$notFound}");
            foreach ($notFoundSlugs as $s) {
                $this->line("  • {$s}");
            }
            Log::warning('seo:import-texts — не найдены slug', $notFoundSlugs);
        } else {
            $this->info('Не найденных slug: 0');
        }

        return self::SUCCESS;
    }

    /**
     * Определить целевую сущность и поле по слагу: категория → description, бренд → short_description.
     *
     * @return array{0: Category|Brand|null, 1: string, 2: string}
     */
    private function resolveTarget(string $slug): array
    {
        $category = Category::where('slug', $slug)->first();
        if ($category !== null) {
            return [$category, 'description', 'категория'];
        }

        $brand = Brand::where('slug', $slug)->first();
        if ($brand !== null) {
            return [$brand, 'short_description', 'бренд'];
        }

        return [null, '', ''];
    }
}

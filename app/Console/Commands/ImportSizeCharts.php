<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use App\Models\SizeChart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportSizeCharts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:import-size-charts 
        {--url= : URL эндпоинта экспорта размерных сеток}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Импорт размерных сеток из формата JSON по URL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->option('url')
            ?: 'https://customers.sex-opt.ru/api/public/export/650?auth_token=kqQKCZA73oORObUK3ApLy7xKJ7FYnYajFRekGsqp';

        $this->info("Загрузка размерных сеток по URL: {$url}");

        $response = Http::timeout(120)->get($url);

        if (!$response->successful()) {
            $this->error("Ошибка загрузки. HTTP код: {$response->status()}");
            return self::FAILURE;
        }

        $items = $response->json();

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
            $this->error("Ошибка парсинга JSON: " . json_last_error_msg());
            return self::FAILURE;
        }

        $totalItems = count($items);

        if ($totalItems === 0) {
            $this->warn('JSON пуст. Нет сеток для загрузки.');
            return self::SUCCESS;
        }

        $this->info("Найдено размерных сеток: {$totalItems}");

        $bar = $this->output->createProgressBar($totalItems);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Импорт сеток...');
        $bar->start();

        $imported = 0;

        foreach ($items as $item) {
            $uid = $item['size_chart_uid'] ?? null;
            $name = $item['size_chart_name'] ?? null;
            $table = $item['size_chart_table'] ?? [];
            $brandExternalIds = $item['size_chart_brands'] ?? [];

            if (!$uid || !$name) {
                $bar->advance();
                continue;
            }

            $bar->setMessage($name);

            try {
                // Создаем или обновляем размерную сетку
                $sizeChart = SizeChart::updateOrCreate(
                    ['uuid' => $uid],
                    [
                        'name' => $name,
                        'values' => $table,
                    ]
                );

                // Привязываем бренды
                if (!empty($brandExternalIds)) {
                    $brandIds = Brand::whereIn('external_id', $brandExternalIds)->pluck('id')->toArray();
                    $sizeChart->brands()->sync($brandIds);
                } else {
                    $sizeChart->brands()->sync([]);
                }

                $imported++;
            } catch (\Exception $e) {
                Log::error("Ошибка импорта размерной сетки {$uid}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Успешно импортировано размерных сеток: {$imported}");

        // Назначаем size_chart_id товарам по связи бренд → размерная сетка
        $this->info('Назначение размерных сеток товарам по брендам...');

        $assignedCount = 0;

        // Собираем бренды и их размерные сетки
        $brands = Brand::whereHas('sizeCharts')->with('sizeCharts')->get();

        foreach ($brands as $brand) {
            $chartIds = $brand->sizeCharts->pluck('id');

            if ($chartIds->count() === 1) {
                // Бренд имеет одну сетку — назначаем всем товарам бренда
                $updated = Product::where('brand_id', $brand->id)
                    ->whereNull('size_chart_id')
                    ->update(['size_chart_id' => $chartIds->first()]);
                $assignedCount += $updated;

                if ($updated > 0) {
                    $this->line("  {$brand->name}: назначено {$updated} товарам");
                }
            } else {
                // Несколько сеток — пробуем сопоставить по ключевым словам в названии категории
                $matched = $this->assignByCategory($brand, $charts);
                $assignedCount += $matched;

                if ($matched > 0) {
                    $this->line("  {$brand->name}: назначено {$matched} товарам (по категориям)");
                }

                $remaining = Product::where('brand_id', $brand->id)->whereNull('size_chart_id')->count();
                if ($remaining > 0) {
                    $this->warn("  {$brand->name}: {$remaining} товаров без сопоставления");
                }
            }
        }

        $this->info("Размерные сетки назначены {$assignedCount} товарам.");

        return self::SUCCESS;
    }

    /**
     * Сопоставить товары бренда с несколькими сетками по ключевым словам категорий.
     *
     * Алгоритм:
     * 1. Извлекаем корни слов (первые 4 символа) из названия каждой сетки.
     * 2. Для каждой категории товаров бренда считаем совпадения корней с названием категории.
     * 3. Назначаем сетку с наибольшим числом совпадений.
     * 4. Фоллбэк: если нет совпадений и среди сеток есть «женское»/«мужское» —
     *    назначаем «женское» всем, кто не в мужских категориях.
     */
    private function assignByCategory(Brand $brand, $charts): int
    {
        // Строим карту ключевых слов для каждой сетки
        $chartKeywords = [];
        foreach ($charts as $chart) {
            $words = preg_split('/[\s,]+/u', mb_strtolower($chart->name));
            $words = array_filter($words, fn ($w) => mb_strlen($w) >= 3);
            // Корни (первые 4 символа) для толерантности к падежам
            $stems = array_map(fn ($w) => mb_substr($w, 0, 4), $words);
            $chartKeywords[$chart->id] = array_values($stems);
        }

        // Получаем категории товаров бренда без size_chart_id
        $categoryIds = Product::where('brand_id', $brand->id)
            ->whereNull('size_chart_id')
            ->distinct()
            ->pluck('category_id')
            ->filter();

        if ($categoryIds->isEmpty()) {
            return 0;
        }

        $categories = \App\Models\Category::whereIn('id', $categoryIds)->get()->keyBy('id');

        $totalAssigned = 0;

        // Фаза 1: Сопоставление по ключевым словам
        foreach ($categories as $catId => $category) {
            $catName = mb_strtolower($category->name);

            $bestChartId = null;
            $bestScore = 0;

            foreach ($chartKeywords as $chartId => $stems) {
                $score = 0;
                foreach ($stems as $stem) {
                    if (mb_strpos($catName, $stem) !== false) {
                        $score++;
                    }
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestChartId = $chartId;
                }
            }

            if ($bestChartId) {
                $updated = Product::where('brand_id', $brand->id)
                    ->where('category_id', $catId)
                    ->whereNull('size_chart_id')
                    ->update(['size_chart_id' => $bestChartId]);
                $totalAssigned += $updated;
            }
        }

        // Фаза 2: Фоллбэк для «женское/мужское» пар
        $remaining = Product::where('brand_id', $brand->id)->whereNull('size_chart_id')->count();
        if ($remaining > 0) {
            $femaleChart = $charts->first(fn ($c) => mb_strpos(mb_strtolower($c->name), 'женс') !== false);
            $maleChart = $charts->first(fn ($c) => mb_strpos(mb_strtolower($c->name), 'мужс') !== false);

            if ($femaleChart && $maleChart) {
                // Мужские категории определяем по маркерам: " М", "Муж", "мужск"
                $malePatterns = [' м', 'муж'];

                // Оставшиеся без назначения — проверяем категорию
                $unassigned = Product::where('brand_id', $brand->id)
                    ->whereNull('size_chart_id')
                    ->with('category')
                    ->get();

                foreach ($unassigned as $product) {
                    $catName = mb_strtolower($product->category?->name ?? '');
                    $isMale = false;
                    foreach ($malePatterns as $pattern) {
                        if (mb_strpos($catName, $pattern) !== false) {
                            $isMale = true;
                            break;
                        }
                    }
                    $product->update([
                        'size_chart_id' => $isMale ? $maleChart->id : $femaleChart->id,
                    ]);
                    $totalAssigned++;
                }
            }
        }

        return $totalAssigned;
    }
}

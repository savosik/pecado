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
                // Несколько сеток — пропускаем (требуется ручное назначение)
                $this->warn("  {$brand->name}: {$chartIds->count()} сеток — пропущено (требуется ручное назначение)");
            }
        }

        $this->info("Размерные сетки назначены {$assignedCount} товарам.");

        return self::SUCCESS;
    }
}

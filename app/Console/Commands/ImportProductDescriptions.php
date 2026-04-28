<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportProductDescriptions extends Command
{
    protected $signature = 'catalog:import-descriptions
        {--url= : URL эндпоинта экспорта описаний товаров}
        {--overwrite : Перезаписывать уже заполненные описания}
        {--chunk=500 : Размер пачки для логирования прогресса}';

    protected $description = 'Импорт описаний товаров (description, description_html, short_description) по UID из внешнего эндпоинта';

    public function handle(): int
    {
        $url = $this->option('url')
            ?: 'https://customers.sex-opt.ru/api/public/export/687?auth_token=kqQKCZA73oORObUK3ApLy7xKJ7FYnYajFRekGsqp';

        $overwrite = (bool) $this->option('overwrite');

        $this->info("Загрузка описаний по URL: {$url}");
        $this->info($overwrite
            ? 'Режим: OVERWRITE — заполненные поля будут перезаписаны.'
            : 'Режим: FILL-ONLY — заполняются только пустые поля.');

        $response = Http::timeout(300)->get($url);

        if (! $response->successful()) {
            $this->error("Ошибка загрузки. HTTP код: {$response->status()}");

            return self::FAILURE;
        }

        $items = $response->json();

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($items)) {
            $this->error('Ошибка парсинга JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        $totalItems = count($items);

        if ($totalItems === 0) {
            $this->warn('JSON пуст. Нет описаний для загрузки.');

            return self::SUCCESS;
        }

        $this->info("Найдено записей: {$totalItems}");

        $bar = $this->output->createProgressBar($totalItems);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Импорт описаний...');
        $bar->start();

        $updated = 0;
        $skippedNoProduct = 0;
        $skippedNoChanges = 0;
        $errors = 0;

        $fields = ['description', 'description_html', 'short_description'];

        foreach ($items as $item) {
            $uid = $item['uid'] ?? null;

            if (! $uid) {
                $bar->advance();
                continue;
            }

            try {
                $product = Product::where('external_id', $uid)->first();

                if (! $product) {
                    $skippedNoProduct++;
                    $bar->advance();
                    continue;
                }

                $bar->setMessage(mb_substr($product->name ?? $uid, 0, 60));

                $changes = [];

                foreach ($fields as $field) {
                    $incoming = $item[$field] ?? null;

                    if ($incoming === null || trim((string) $incoming) === '') {
                        continue;
                    }

                    $current = $product->{$field};
                    $isEmpty = $current === null || trim((string) $current) === '';

                    if ($overwrite || $isEmpty) {
                        if ($current !== $incoming) {
                            $changes[$field] = $incoming;
                        }
                    }
                }

                if (empty($changes)) {
                    $skippedNoChanges++;
                    $bar->advance();
                    continue;
                }

                $product->fill($changes);
                $product->saveQuietly();

                $updated++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error("Ошибка импорта описания для UID {$uid}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Обновлено товаров: {$updated}");
        $this->line("Пропущено (товар не найден): {$skippedNoProduct}");
        $this->line("Пропущено (нечего обновлять): {$skippedNoChanges}");

        if ($errors > 0) {
            $this->warn("Ошибок при обработке: {$errors} (см. лог)");
        }

        return self::SUCCESS;
    }
}

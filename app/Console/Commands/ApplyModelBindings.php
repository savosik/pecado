<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyModelBindings extends Command
{
    protected $signature = 'products:apply-model-bindings
                            {--from= : Путь к JSON-артефакту привязок (по external_id+sku)}
                            {--dry-run : Только вывести статистику, не записывать в БД}';

    protected $description = 'Создаёт ProductModel и привязывает товары через external_id+sku из артефакта product-model-bindings.json.';

    public function handle(): int
    {
        $path = $this->option('from')
            ?? base_path('database/data/product-model-bindings-latest.json');

        if (! file_exists($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');

        $artifact = json_decode(file_get_contents($path), true);
        if (! is_array($artifact) || ! isset($artifact['models'])) {
            $this->error('Неверный формат артефакта: ожидается JSON с ключом "models".');

            return self::FAILURE;
        }

        $this->info("Артефакт: {$path}");
        $this->info("Версия: {$artifact['version']} | Сгенерирован: {$artifact['generated_at']}");
        $this->info('Моделей в артефакте: '.count($artifact['models']));

        $modelsCreated = 0;
        $productsUpdated = 0;
        $productsNotFound = 0;
        $foundByExternalId = 0;
        $foundBySku = 0;

        $logPath = storage_path('app/product-models/apply-log-'.now()->format('Ymd-His').'.log');
        $log = $isDryRun ? null : fopen($logPath, 'w');

        foreach ($artifact['models'] as $model) {
            $name = $model['name'] ?? null;
            $products = $model['products'] ?? [];
            if (! $name || empty($products)) {
                continue;
            }

            // Сначала находим/создаём ProductModel
            $pm = null;
            if (! $isDryRun) {
                $pm = ProductModel::firstOrCreate(['name' => $name]);
            }

            $modelUpdated = 0;
            DB::transaction(function () use (&$modelUpdated, &$productsUpdated, &$productsNotFound, &$foundByExternalId, &$foundBySku, $products, $pm, $name, $isDryRun, $log) {
                foreach ($products as $p) {
                    $extId = $p['external_id'] ?? null;
                    $sku = $p['sku'] ?? null;
                    $variantName = $p['variant_name'] ?? null;

                    // Поиск: сначала по external_id, затем fallback по sku
                    $product = null;
                    $foundBy = null;
                    if ($extId) {
                        $product = Product::withoutGlobalScopes()
                            ->where('external_id', $extId)
                            ->first();
                        if ($product) {
                            $foundBy = 'external_id';
                        }
                    }
                    if (! $product && $sku) {
                        $product = Product::withoutGlobalScopes()
                            ->where('sku', $sku)
                            ->first();
                        if ($product) {
                            $foundBy = 'sku';
                        }
                    }

                    if (! $product) {
                        $productsNotFound++;
                        if ($log) {
                            fwrite($log, "NOT_FOUND model={$name} external_id={$extId} sku={$sku}\n");
                        }

                        continue;
                    }

                    if ($foundBy === 'external_id') {
                        $foundByExternalId++;
                    } elseif ($foundBy === 'sku') {
                        $foundBySku++;
                    }

                    if (! $isDryRun) {
                        Product::withoutGlobalScopes()->where('id', $product->id)->update([
                            'model_id' => $pm->id,
                            'variant_name' => $variantName,
                        ]);
                        if ($log) {
                            fwrite($log, "OK product_id={$product->id} model_id={$pm->id} found_by={$foundBy} variant={$variantName}\n");
                        }
                    }
                    $modelUpdated++;
                    $productsUpdated++;
                }
            });

            if ($modelUpdated > 0) {
                $modelsCreated++;
                if ($isDryRun) {
                    $this->line("  [dry-run] «{$name}» → {$modelUpdated} товаров");
                }
            }
        }

        if ($log) {
            fclose($log);
        }

        $prefix = $isDryRun ? '[dry-run] ' : '';
        $this->newLine();
        $this->info("{$prefix}Создано/найдено ProductModel: {$modelsCreated}");
        $this->info("{$prefix}Обновлено товаров: {$productsUpdated}");
        $this->info("{$prefix}  Найдено по external_id: {$foundByExternalId}");
        $this->info("{$prefix}  Найдено по sku (fallback): {$foundBySku}");
        if ($productsNotFound > 0) {
            $this->warn("{$prefix}Не найдено товаров (external_id и sku): {$productsNotFound}");
        }
        if (! $isDryRun) {
            $this->line("Лог изменений: {$logPath}");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая команда для привязки атрибутов к категориям
 * на основе атрибутов товаров, уже присвоенных этим категориям.
 */
class SyncAttributesToCategories extends Command
{
    protected $signature = 'attributes:sync-to-categories
                            {--dry-run : Показать что будет привязано без выполнения}';

    protected $description = 'Привязать атрибуты к категориям на основе атрибутов товаров в этих категориях';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $categories = Category::whereHas('products.attributeValues')->get();

        if ($categories->isEmpty()) {
            $this->info('Нет категорий с товарами, имеющими атрибуты.');

            return self::SUCCESS;
        }

        $this->info("Найдено категорий с товарами: {$categories->count()}");

        $totalBound = 0;

        foreach ($categories as $category) {
            // Собираем уникальные attribute_id из product_attribute_values товаров этой категории
            $attributeIds = DB::table('product_attribute_values')
                ->join('products', 'products.id', '=', 'product_attribute_values.product_id')
                ->where('products.category_id', $category->id)
                ->distinct()
                ->pluck('product_attribute_values.attribute_id')
                ->toArray();

            if (empty($attributeIds)) {
                continue;
            }

            // Узнаём сколько уже привязано
            $existingCount = $category->attributes()->count();

            if ($dryRun) {
                $newCount = count(array_diff(
                    $attributeIds,
                    $category->attributes()->pluck('attributes.id')->toArray()
                ));
                $this->line("  [{$category->id}] {$category->name}: +{$newCount} новых атрибутов (всего {$existingCount} уже привязано)");
                $totalBound += $newCount;
            } else {
                $category->attributes()->syncWithoutDetaching($attributeIds);
                $afterCount = $category->attributes()->count();
                $newCount = $afterCount - $existingCount;
                $this->line("  [{$category->id}] {$category->name}: +{$newCount} новых атрибутов (всего {$afterCount})");
                $totalBound += $newCount;
            }
        }

        $prefix = $dryRun ? '[DRY RUN] Будет привязано' : 'Привязано';
        $this->info("{$prefix}: {$totalBound} новых связей атрибут-категория.");

        return self::SUCCESS;
    }
}

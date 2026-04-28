<?php

namespace App\Console\Commands;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\ShipmentItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Заполняет snapshot-поля product_name_snapshot/brand_name_snapshot
 * для существующих позиций документов кабинета (заказов/возвратов/реализаций).
 * Используется один раз после миграции, перед `scout:import`.
 */
class BackfillItemSnapshots extends Command
{
    protected $signature = 'cabinet-search:backfill-item-snapshots
                            {--model= : order|return|shipment (по умолчанию все три)}
                            {--chunk=500 : Размер пачки}
                            {--dry-run : Показать сколько строк будет обновлено без изменений}';

    protected $description = 'Заполнить snapshot-поля имени товара и бренда для документов кабинета';

    public function handle(): int
    {
        $only = (string) $this->option('model');
        $chunk = max(50, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $targets = [
            'order' => [OrderItem::class, 'name', 'brand_name_snapshot'],
            'return' => [ReturnItem::class, 'product_name_snapshot', 'brand_name_snapshot'],
            'shipment' => [ShipmentItem::class, 'product_name_snapshot', 'brand_name_snapshot'],
        ];

        if ($only !== '' && ! isset($targets[$only])) {
            $this->error("Неизвестная модель: {$only}. Допустимые: ".implode(', ', array_keys($targets)));

            return self::FAILURE;
        }

        $selected = $only !== '' ? [$only => $targets[$only]] : $targets;

        foreach ($selected as $key => [$class, $nameField, $brandField]) {
            $this->processModel($key, $class, $nameField, $brandField, $chunk, $dryRun);
        }

        return self::SUCCESS;
    }

    private function processModel(string $key, string $class, string $nameField, string $brandField, int $chunk, bool $dryRun): void
    {
        $this->info("=== {$key} ===");

        /** @var Builder<Model> $query */
        $query = $class::query()
            ->whereNotNull('product_id')
            ->where(function (Builder $q) use ($nameField, $brandField) {
                $q->whereNull($nameField)
                    ->orWhere($nameField, '')
                    ->orWhereNull($brandField)
                    ->orWhere($brandField, '');
            });

        $total = (clone $query)->count();
        $this->line("  К обновлению: {$total}");

        if ($total === 0) {
            return;
        }

        if ($dryRun) {
            $this->warn('  [dry-run] изменения не вносятся');

            return;
        }

        $updated = 0;
        $missingProducts = 0;

        $query->select(['id', 'product_id', $nameField, $brandField])
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($class, $nameField, $brandField, &$updated, &$missingProducts) {
                $productIds = $rows->pluck('product_id')->unique()->values();
                $products = Product::with('brand:id,name')
                    ->whereIn('id', $productIds)
                    ->get()
                    ->keyBy('id');

                foreach ($rows as $row) {
                    $productId = data_get($row, 'product_id');
                    $product = $products->get($productId);
                    if (! $product) {
                        $missingProducts++;

                        continue;
                    }

                    $payload = [];
                    if (empty(data_get($row, $nameField))) {
                        $payload[$nameField] = (string) data_get($product, 'name');
                    }
                    if (empty(data_get($row, $brandField))) {
                        $brandName = data_get($product, 'brand.name');
                        $payload[$brandField] = $brandName !== null ? (string) $brandName : null;
                    }

                    if (! empty($payload)) {
                        DB::table((new $class)->getTable())
                            ->where('id', data_get($row, 'id'))
                            ->update($payload);
                        $updated++;
                    }
                }
            });

        $this->info("  Обновлено: {$updated}");
        if ($missingProducts > 0) {
            $this->warn("  Пропущено (товар удалён): {$missingProducts}");
        }
    }
}

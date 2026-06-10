<?php

namespace App\Console\Commands;

use App\Models\ProductExport;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Диагностика лазейки: выгрузки, которые отдают клиенту остатки складов,
 * недоступных его региону (по region_warehouse).
 *
 * Контекст: складские поля выгрузки (warehouse.{id}.quantity,
 * warehouses.pivot.quantity, warehouses.name, total_stock) исторически читали
 * остатки по всем складам без фильтра по региону. Партнёры, настроившие
 * выгрузку с колонкой склада (например «Москва персональный») ДО введения
 * региональных ограничений, продолжали получать по ней остатки даже после
 * того, как их регион доступ к складу потерял.
 *
 * Команда только читает — ничего не меняет. Печатает по складу:
 *  - какие регионы к нему имеют доступ;
 *  - какие выгрузки отдают его остатки клиенту, чей регион доступа не имеет.
 *
 * По умолчанию проверяется склад «Москва персональный»; через --warehouse
 * можно проверить любой склад по части имени.
 */
class AuditExportWarehouseLeaks extends Command
{
    protected $signature = 'exports:audit-warehouse-leaks
        {--warehouse=Москва персональный : Часть имени склада для проверки}';

    protected $description = 'Найти выгрузки, отдающие клиентам остатки складов вне их региона';

    /** Поля, выводящие остатки сразу по всем складам товара. */
    private const BROAD_STOCK_FIELDS = [
        'warehouses.pivot.quantity',
        'warehouses.name',
        'total_stock',
    ];

    public function handle(): int
    {
        $needle = (string) $this->option('warehouse');

        $warehouses = Warehouse::query()
            ->where('name', 'like', "%{$needle}%")
            ->orderBy('name')
            ->get();

        if ($warehouses->isEmpty()) {
            $this->error("Склады по запросу «{$needle}» не найдены.");

            return self::FAILURE;
        }

        $totalLeaks = 0;

        foreach ($warehouses as $warehouse) {
            $totalLeaks += $this->auditWarehouse($warehouse);
        }

        $this->newLine();
        if ($totalLeaks === 0) {
            $this->info('Лазеек не найдено: ни одна выгрузка не отдаёт остатки склада клиенту вне его региона.');
        } else {
            $this->warn("Итого затронутых выгрузок: {$totalLeaks}.");
            $this->line('Лазейка закрывается на этапе генерации (RestrictsWarehousesByRegion): после деплоя такие колонки вернут 0/пусто.');
        }

        return self::SUCCESS;
    }

    private function auditWarehouse(Warehouse $warehouse): int
    {
        $this->newLine();
        $this->info("=== Склад #{$warehouse->id}: {$warehouse->name} ===");

        // Регионы, у которых склад доступен (любой тип: primary/preorder).
        $allowedRegionIds = DB::table('region_warehouse')
            ->where('warehouse_id', $warehouse->id)
            ->pluck('region_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allowedRegionNames = DB::table('regions')
            ->whereIn('id', $allowedRegionIds)
            ->pluck('name')
            ->all();

        $this->line('Доступен регионам: '.($allowedRegionNames === []
            ? '— (ни одному)'
            : implode(', ', $allowedRegionNames)));

        $warehouseFieldKey = "warehouse.{$warehouse->id}.quantity";

        $rows = [];

        ProductExport::query()
            // Лазейка касается только партнёрских выгрузок (есть получатель-клиент).
            // Админские выгрузки без клиента складами не режутся и в аудит не идут.
            ->whereNotNull('client_user_id')
            ->with('clientUser:id,name,email,region_id')
            ->chunkById(200, function ($exports) use ($allowedRegionIds, $warehouseFieldKey, &$rows) {
                foreach ($exports as $export) {
                    $leakField = $this->leakingField($export, $warehouseFieldKey);
                    if ($leakField === null) {
                        continue;
                    }

                    $client = $export->clientUser;
                    $regionId = $client->region_id;

                    // Если регион клиента имеет доступ к складу — это не лазейка.
                    if ($regionId !== null && in_array((int) $regionId, $allowedRegionIds, true)) {
                        continue;
                    }

                    $rows[] = [
                        'export_id' => $export->id,
                        'export' => $export->name,
                        'client_id' => $client->id,
                        'client' => $client->email ?? $client->name,
                        'region' => $this->regionLabel($regionId),
                        'field' => $leakField,
                        'active' => $export->is_active ? 'да' : 'нет',
                    ];
                }
            });

        if ($rows === []) {
            $this->line('Затронутых выгрузок нет.');

            return 0;
        }

        $this->table(
            ['Export ID', 'Выгрузка', 'Client ID', 'Клиент', 'Регион клиента', 'Поле-утечка', 'Активна'],
            $rows
        );

        // Уникальные клиенты — это и есть искомые пользователи.
        $clientIds = collect($rows)->pluck('client_id')->unique();
        $this->line('Уникальные клиенты (получатели утечки): '.$clientIds->implode(', '));

        return count($rows);
    }

    /**
     * Ключ поля выгрузки, который отдаёт остатки данного склада, либо null.
     */
    private function leakingField(ProductExport $export, string $warehouseFieldKey): ?string
    {
        foreach ($export->fields ?? [] as $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : $field;
            if ($key === null) {
                continue;
            }

            if ($key === $warehouseFieldKey) {
                return $key;
            }

            if (in_array($key, self::BROAD_STOCK_FIELDS, true)) {
                return $key;
            }
        }

        return null;
    }

    private function regionLabel(?int $regionId): string
    {
        if ($regionId === null) {
            return '— (не задан)';
        }

        $name = DB::table('regions')->where('id', $regionId)->value('name');

        return $name ? "{$name} (#{$regionId})" : "#{$regionId}";
    }
}

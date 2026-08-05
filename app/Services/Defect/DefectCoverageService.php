<?php

namespace App\Services\Defect;

use App\Models\Warehouse;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Сопоставление остатков склада некондиции с заведёнными партиями брака.
 *
 * 1С присылает остатки по складам некондиции (product_warehouse), кладовщик
 * заводит на них партии (product_defects). Товар, у которого остаток есть, а
 * партии нет, нигде не предлагается: на витрину уценка попадает только через
 * партию. Этот сервис считает такой «висящий в воздухе» остаток потоварно.
 *
 * Покрытием считается любая открытая партия (closed_at IS NULL), независимо от
 * цены и публикации: завести партию — зона ответственности кладовщика, а цену
 * ставит закупщик. Партии без цены/публикации отдаются отдельным счётчиком
 * (idle_quantity), чтобы было видно, что застряло на стороне закупщика.
 *
 * Разница считается по паре товар + склад: складов некондиции может быть
 * несколько, и остаток одного не закрывается партией на другом.
 */
class DefectCoverageService
{
    /** Непокрытый остаток: сколько числится в 1С минус объём открытых партий. */
    private const UNCOVERED = 'COALESCE(pw.quantity, 0) - COALESCE(batches.covered_quantity, 0)';

    /** Только непокрытые позиции: остаток есть, партий на него не хватает. */
    public const FILTER_UNCOVERED = 'uncovered';

    /** Партий заведено больше, чем числится остатка, — расхождение с 1С. */
    public const FILTER_OVER = 'over';

    /** Все позиции склада некондиции, включая полностью закрытые партиями. */
    public const FILTER_ALL = 'all';

    /**
     * Строки отчёта: товар + склад + остаток + покрытие.
     *
     * Возвращается query builder без сортировки и пагинации — их задаёт
     * вызывающий код.
     */
    public function rows(string $filter = self::FILTER_UNCOVERED, ?string $search = null, ?int $warehouseId = null): Builder
    {
        $query = $this->baseQuery($search, $warehouseId);

        return match ($filter) {
            self::FILTER_OVER => $query->whereRaw(self::UNCOVERED.' < 0'),
            self::FILTER_ALL => $query,
            default => $query->whereRaw(self::UNCOVERED.' > 0'),
        };
    }

    /**
     * Сводка по всем складам некондиции — не зависит от выбранного фильтра,
     * иначе счётчики менялись бы вместе с таблицей и сравнивать было бы не с чем.
     *
     * @return array{uncovered_positions: int, uncovered_units: int, over_positions: int, idle_units: int}
     */
    public function stats(?string $search = null, ?int $warehouseId = null): array
    {
        $row = DB::query()
            ->fromSub($this->baseQuery($search, $warehouseId), 'report')
            ->selectRaw('
                SUM(CASE WHEN uncovered_quantity > 0 THEN 1 ELSE 0 END) as uncovered_positions,
                SUM(CASE WHEN uncovered_quantity > 0 THEN uncovered_quantity ELSE 0 END) as uncovered_units,
                SUM(CASE WHEN uncovered_quantity < 0 THEN 1 ELSE 0 END) as over_positions,
                SUM(idle_quantity) as idle_units
            ')
            ->first();

        return [
            'uncovered_positions' => (int) ($row->uncovered_positions ?? 0),
            'uncovered_units' => (int) ($row->uncovered_units ?? 0),
            'over_positions' => (int) ($row->over_positions ?? 0),
            'idle_units' => (int) ($row->idle_units ?? 0),
        ];
    }

    /**
     * Склады некондиции для фильтра.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function warehouses(): array
    {
        return Warehouse::query()
            ->defect()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Warehouse $warehouse) => [
                'id' => (int) $warehouse->id,
                'name' => $warehouse->name,
            ])
            ->all();
    }

    /**
     * Остаток и партии, сведённые по паре товар + склад.
     *
     * Набор пар собирается объединением обеих сторон: только остатков мало
     * (партия может пережить обнулившийся остаток — тогда её нужно показать
     * как расхождение), только партий — тоже (непокрытый остаток партии не имеет).
     */
    private function baseQuery(?string $search, ?int $warehouseId): Builder
    {
        $warehouseIds = Warehouse::query()->defect()->pluck('id')->all();

        if ($warehouseIds === []) {
            // Складов некондиции нет — отчёт пустой, но запрос должен остаться
            // валидным builder-ом для пагинации.
            $warehouseIds = [0];
        }

        $stockPairs = DB::table('product_warehouse')
            ->select('product_id', 'warehouse_id')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '<>', 0);

        $batchPairs = DB::table('product_defects')
            ->select('product_id', 'warehouse_id')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNull('deleted_at')
            ->whereNull('closed_at');

        $batches = DB::table('product_defects')
            ->selectRaw('
                product_id,
                warehouse_id,
                SUM(quantity) as covered_quantity,
                SUM(CASE WHEN price IS NULL OR is_published = 0 THEN quantity ELSE 0 END) as idle_quantity,
                COUNT(*) as batches_count
            ')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNull('deleted_at')
            ->whereNull('closed_at')
            ->groupBy('product_id', 'warehouse_id');

        return DB::query()
            ->fromSub($stockPairs->union($batchPairs), 'pairs')
            ->join('products', 'products.id', '=', 'pairs.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'pairs.warehouse_id')
            ->leftJoin('product_warehouse as pw', function ($join) {
                $join->on('pw.product_id', '=', 'pairs.product_id')
                    ->on('pw.warehouse_id', '=', 'pairs.warehouse_id');
            })
            ->leftJoinSub($batches, 'batches', function ($join) {
                $join->on('batches.product_id', '=', 'pairs.product_id')
                    ->on('batches.warehouse_id', '=', 'pairs.warehouse_id');
            })
            ->when($warehouseId, fn (Builder $q) => $q->where('pairs.warehouse_id', $warehouseId))
            ->when($search, function (Builder $q) use ($search) {
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('products.name', 'like', "%{$search}%")
                        ->orWhere('products.sku', 'like', "%{$search}%");
                });
            })
            ->select([
                'pairs.product_id',
                'pairs.warehouse_id',
                'products.name as product_name',
                'products.sku as product_sku',
                'warehouses.name as warehouse_name',
                DB::raw('COALESCE(pw.quantity, 0) as stock_quantity'),
                DB::raw('COALESCE(batches.covered_quantity, 0) as covered_quantity'),
                DB::raw('COALESCE(batches.idle_quantity, 0) as idle_quantity'),
                DB::raw('COALESCE(batches.batches_count, 0) as batches_count'),
                DB::raw(self::UNCOVERED.' as uncovered_quantity'),
            ]);
    }
}

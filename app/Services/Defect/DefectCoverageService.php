<?php

namespace App\Services\Defect;

use App\Enums\OrderType;
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
 *
 * Сравниваем свободное со свободным. 1С присылает по некондиции не то, что
 * лежит на полке, а остаток за вычетом резерва: заказ уценки уменьшает
 * stock.updated в момент резервирования, до отгрузки (проверено на проде
 * 07.09.2026: order.updated и падение остатка 3 → 1 пришли в одну секунду, расходный
 * ордер — через две минуты). Партия же описывает полку и закрывается только по
 * реализации. Поэтому из объёма партий вычитается резерв живых заказов уценки,
 * иначе каждый заказ между оформлением и отгрузкой выглядит как расхождение.
 *
 * Резерв считается только по открытым партиям: закрытая партия из покрытия уже
 * ушла, а позиции заказа ссылаться на неё не перестают — вычесть её резерв
 * второй раз нельзя. Резерв одной партии ограничен её объёмом, как в
 * DefectStockService::available().
 */
class DefectCoverageService
{
    /** Свободно в партиях: объём открытых партий минус резерв живых заказов уценки. */
    private const FREE = '(COALESCE(batches.covered_quantity, 0) - COALESCE(batches.reserved_quantity, 0))';

    /** Непокрытый остаток: свободно в 1С минус свободно в партиях. */
    private const UNCOVERED = 'COALESCE(pw.quantity, 0) - '.self::FREE;

    /** Только непокрытые позиции: остаток есть, партий на него не хватает. */
    public const FILTER_UNCOVERED = 'uncovered';

    /** Свободного в партиях больше, чем свободно в 1С, — расхождение с 1С. */
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
     * Остаток 1С и объём открытых партий по конкретным парам товар + склад.
     *
     * Нужен спискам партий: рядом с «заведено складом» показываем, сколько по
     * этому товару вообще числится на складе некондиции в 1С и сколько из
     * остатка уже разобрано партиями. По типу склада здесь не фильтруем —
     * партия живёт на том складе, на котором её завели, и показать её остаток
     * нужно в любом случае.
     *
     * Резерв (reserved) — та часть объёма открытых партий, что уже в живых
     * заказах уценки; с остатком 1С сравнивается covered − reserved.
     *
     * @param  iterable<array{0: int, 1: int}>  $pairs  [[product_id, warehouse_id], …]
     * @return array<string, array{stock: int, covered: int, reserved: int}> ключ — pairKey()
     */
    public function pairTotals(iterable $pairs): array
    {
        $totals = [];
        $productIds = [];
        $warehouseIds = [];

        foreach ($pairs as [$productId, $warehouseId]) {
            $productId = (int) $productId;
            $warehouseId = (int) $warehouseId;

            $totals[self::pairKey($productId, $warehouseId)] = ['stock' => 0, 'covered' => 0, 'reserved' => 0];
            $productIds[$productId] = true;
            $warehouseIds[$warehouseId] = true;
        }

        if ($totals === []) {
            return [];
        }

        $productIds = array_keys($productIds);
        $warehouseIds = array_keys($warehouseIds);

        $stock = DB::table('product_warehouse')
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get(['product_id', 'warehouse_id', 'quantity']);

        foreach ($stock as $row) {
            $key = self::pairKey((int) $row->product_id, (int) $row->warehouse_id);

            if (isset($totals[$key])) {
                $totals[$key]['stock'] = (int) $row->quantity;
            }
        }

        $covered = $this->openBatches()
            ->whereIn('product_defects.product_id', $productIds)
            ->whereIn('product_defects.warehouse_id', $warehouseIds)
            ->get();

        foreach ($covered as $row) {
            $key = self::pairKey((int) $row->product_id, (int) $row->warehouse_id);

            if (isset($totals[$key])) {
                $totals[$key]['covered'] = (int) $row->covered_quantity;
                $totals[$key]['reserved'] = (int) $row->reserved_quantity;
            }
        }

        return $totals;
    }

    /** Ключ пары товар + склад для карт остатка и покрытия. */
    public static function pairKey(int $productId, int $warehouseId): string
    {
        return $productId.':'.$warehouseId;
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
     * Открытые партии, сведённые по паре товар + склад: объём, резерв, простой.
     *
     * «Резерв» здесь — всё, что в открытой партии занято живым заказом уценки,
     * независимо от стадии: ждёт отгрузки или уже уехало по реализации. Отгруженное
     * тоже вычитается, потому что объём партии при отгрузке не уменьшается — партия
     * закрывается целиком, когда отгружено всё; до этого уехавшие штуки сидят в её
     * числе, а в свободном остатке 1С их уже нет. Не резерв: удалённый заказ
     * (отмена по любому пути делает soft-delete, поэтому по статусу заказа не
     * фильтруем) и строка, отменённая 1С при недоборе (cancelled) — то же правило,
     * что в DefectStockService::reservedMap(). Резерв партии ограничен её объёмом:
     * лишнее (заказали больше, чем в партии) в свободное не уходит.
     */
    private function openBatches(): Builder
    {
        $reserves = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.type', OrderType::DEFECT->value)
            ->whereNull('orders.deleted_at')
            ->where('order_items.cancelled', false)
            ->whereNotNull('order_items.product_defect_id')
            ->selectRaw('order_items.product_defect_id, SUM(order_items.quantity) as reserved')
            ->groupBy('order_items.product_defect_id');

        return DB::table('product_defects')
            ->leftJoinSub($reserves, 'reserves', 'reserves.product_defect_id', '=', 'product_defects.id')
            ->selectRaw('
                product_defects.product_id,
                product_defects.warehouse_id,
                SUM(product_defects.quantity) as covered_quantity,
                SUM(CASE
                    WHEN COALESCE(reserves.reserved, 0) > product_defects.quantity THEN product_defects.quantity
                    ELSE COALESCE(reserves.reserved, 0)
                END) as reserved_quantity,
                SUM(CASE WHEN product_defects.price IS NULL OR product_defects.is_published = 0 THEN product_defects.quantity ELSE 0 END) as idle_quantity,
                COUNT(*) as batches_count
            ')
            ->whereNull('product_defects.deleted_at')
            ->whereNull('product_defects.closed_at')
            ->groupBy('product_defects.product_id', 'product_defects.warehouse_id');
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

        $batches = $this->openBatches()->whereIn('product_defects.warehouse_id', $warehouseIds);

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
                DB::raw('COALESCE(batches.reserved_quantity, 0) as reserved_quantity'),
                DB::raw(self::FREE.' as free_quantity'),
                DB::raw('COALESCE(batches.idle_quantity, 0) as idle_quantity'),
                DB::raw('COALESCE(batches.batches_count, 0) as batches_count'),
                DB::raw(self::UNCOVERED.' as uncovered_quantity'),
            ]);
    }
}

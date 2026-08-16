<?php

namespace App\Services\Stock;

use App\Models\ProductStockBuffer;
use App\Models\Region;
use Illuminate\Support\Facades\DB;

/**
 * Ночной пересчёт страхового буфера по сигналам риска (эпик buf-00, buf-01).
 *
 * Сигналы: отмены строк при сборке за скользящее окно, партии брака
 * (`product_defects`), близкий срок годности (атрибут товара). Ручные пометки
 * склада (`manual_qty`) пересчёт никогда не трогает.
 *
 * Размер: `clamp(сумма весов сигналов, 0, min(max_qty, ceil(share × остаток)))`.
 * Остаток берётся по primary-складам региона по умолчанию — как для гостей
 * каталога. Треть позиций склада лежит по 1–2 шт, поэтому для рискового SKU
 * с таким остатком буфер честно даёт ноль: запись остаётся с раскладкой
 * сигналов для WMS-консоли, но показ не занижается.
 *
 * Пересчёт идемпотентен и пишет только реально изменившиеся строки:
 * «ничего не изменилось» не трогает ни одной записи — на этом стоит условная
 * инвалидация кешей в buf-05.
 */
class StockBufferRecalculator
{
    /**
     * Пересчитать буферы и вернуть дифф.
     *
     * @return array{
     *     changed: array<int, array{before: int, after: int}>,
     *     rows: int,
     *     hidden_units: int,
     * } `changed` — товары, у которых эффективный буфер реально изменился;
     *   `rows` — записей с сигналами после пересчёта; `hidden_units` — суммарно скрытых штук.
     */
    public function recompute(): array
    {
        $signals = $this->collectSignals();
        $stock = $this->defaultRegionStockMap(array_keys($signals));

        // Сигнал «срок годности» имеет смысл только при живом остатке: на копии
        // прода 943 из 957 товаров с датой ближе порога распроданы в ноль —
        // это шум, а не рисковая полка. Отмены и брак остаются и при нулевом
        // остатке: они объясняют историю SKU в WMS-консоли.
        foreach ($signals as $productId => $reasons) {
            if (isset($reasons['shelf_life']) && ($stock[$productId] ?? 0) <= 0) {
                unset($reasons['shelf_life']);

                if ($reasons === []) {
                    unset($signals[$productId]);

                    continue;
                }

                $signals[$productId] = $reasons;
            }
        }

        $existing = ProductStockBuffer::query()->get()->keyBy('product_id');

        $now = now();
        $changed = [];
        $rows = 0;
        $hiddenUnits = 0;

        foreach ($signals as $productId => $reasons) {
            $computedQty = $this->clampQty($this->baseQty($reasons), $stock[$productId] ?? 0);

            /** @var ProductStockBuffer|null $row */
            $row = $existing->pull($productId);

            $effectiveBefore = $row?->effectiveQty() ?? 0;

            if ($row === null) {
                $row = new ProductStockBuffer(['product_id' => $productId]);
            }

            if ((int) $row->buffer_qty !== $computedQty || $row->reasons !== $reasons) {
                $row->fill([
                    'buffer_qty' => $computedQty,
                    'reasons' => $reasons,
                    'computed_at' => $now,
                ])->save();
            }

            $rows++;
            $hiddenUnits += $row->effectiveQty();

            if ($row->effectiveQty() !== $effectiveBefore) {
                $changed[$productId] = ['before' => $effectiveBefore, 'after' => $row->effectiveQty()];
            }
        }

        // Товары, потерявшие все сигналы: расчётный буфер обнуляется. Запись
        // без ручной пометки удаляется (отсутствие записи = 0), с ручной —
        // остаётся, потому что manual_qty продолжает действовать.
        foreach ($existing as $productId => $row) {
            $effectiveBefore = $row->effectiveQty();

            if ($row->manual_qty === null) {
                $row->delete();

                if ($effectiveBefore !== 0) {
                    $changed[$productId] = ['before' => $effectiveBefore, 'after' => 0];
                }

                continue;
            }

            if ((int) $row->buffer_qty !== 0 || $row->reasons !== null) {
                $row->fill([
                    'buffer_qty' => 0,
                    'reasons' => null,
                    'computed_at' => $now,
                ])->save();
            }

            $rows++;
            $hiddenUnits += $row->effectiveQty();
            // manual_qty задан, поэтому эффективный буфер не менялся — в дифф не попадает.
        }

        return ['changed' => $changed, 'rows' => $rows, 'hidden_units' => $hiddenUnits];
    }

    /**
     * Собрать сигналы риска: товар → раскладка для WMS-консоли.
     *
     * @return array<int, array<string, int|bool>>
     */
    private function collectSignals(): array
    {
        $signals = [];

        foreach ($this->cancellationCounts() as $productId => $count) {
            $signals[$productId]['cancellations'] = $count;
        }

        foreach ($this->defectBatchCounts() as $productId => $count) {
            $signals[$productId]['defect_batches'] = $count;
        }

        foreach ($this->nearShelfLifeProductIds() as $productId) {
            $signals[$productId]['shelf_life'] = true;
        }

        // Стабильный порядок ключей: сравнение reasons при повторном пересчёте
        // не должно видеть «изменение» из-за перестановки сигналов.
        foreach ($signals as $productId => $reasons) {
            ksort($reasons);
            $signals[$productId] = $reasons;
        }

        return $signals;
    }

    /**
     * Отмены строк при сборке за скользящее окно: товар → число отмен.
     *
     * Датой заказа считается COALESCE(erp_created_at, created_at) — правило
     * бизнес-дат аналитики (как в ShortageAnalyticsService).
     *
     * @return array<int, int>
     */
    private function cancellationCounts(): array
    {
        $since = now()
            ->subDays((int) config('stock_buffer.cancellations.window_days'))
            ->toDateTimeString();

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.cancelled', true)
            ->whereNotNull('order_items.product_id')
            ->whereNull('orders.deleted_at')
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) >= ?', [$since])
            ->groupBy('order_items.product_id')
            ->havingRaw('COUNT(*) >= ?', [(int) config('stock_buffer.cancellations.min_events')])
            ->selectRaw('order_items.product_id, COUNT(*) as events')
            ->pluck('events', 'product_id')
            ->mapWithKeys(fn ($events, $productId) => [(int) $productId => (int) $events])
            ->all();
    }

    /**
     * Партии брака: открытые всегда, закрытые — за скользящее окно.
     *
     * @return array<int, int>
     */
    private function defectBatchCounts(): array
    {
        $closedSince = now()
            ->subDays((int) config('stock_buffer.defects.closed_window_days'))
            ->toDateTimeString();

        return DB::table('product_defects')
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query
                ->whereNull('closed_at')
                ->orWhere('closed_at', '>=', $closedSince))
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as batches')
            ->pluck('batches', 'product_id')
            ->mapWithKeys(fn ($batches, $productId) => [(int) $productId => (int) $batches])
            ->all();
    }

    /**
     * Товары со сроком годности ближе порога (включая уже истёкший).
     *
     * Партийных сроков в БД нет — только date-time атрибут товара
     * (catalog.shelf_life_attribute_slug), поэтому сигнал грубый: «где-то на
     * полке может лежать старая единица».
     *
     * @return list<int>
     */
    private function nearShelfLifeProductIds(): array
    {
        $threshold = now()
            ->addMonths((int) config('stock_buffer.shelf_life.threshold_months'))
            ->toDateTimeString();

        return DB::table('product_attribute_values')
            ->join('attributes', 'attributes.id', '=', 'product_attribute_values.attribute_id')
            ->where('attributes.slug', config('catalog.shelf_life_attribute_slug'))
            ->whereNotNull('product_attribute_values.datetime_value')
            ->where('product_attribute_values.datetime_value', '<=', $threshold)
            ->pluck('product_attribute_values.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * База по сигналам: сумма весов сработавших.
     *
     * @param  array<string, int|bool>  $reasons
     */
    private function baseQty(array $reasons): int
    {
        $weights = (array) config('stock_buffer.size.signal_weights');

        $base = 0;
        foreach (array_keys($reasons) as $signal) {
            $base += (int) ($weights[$signal] ?? 0);
        }

        return $base;
    }

    /**
     * clamp(база, 0, min(max_qty, ceil(share × остаток))).
     *
     * Остаток ниже min_stock даёт ноль без формулы: для SKU с 1–2 шт на полке
     * у кладовщика всё равно нет выбора единицы, а занижение спрятало бы товар.
     */
    private function clampQty(int $base, int $stock): int
    {
        if ($stock < (int) config('stock_buffer.size.min_stock')) {
            return 0;
        }

        $cap = min(
            (int) config('stock_buffer.size.max_qty'),
            (int) ceil((float) config('stock_buffer.size.max_stock_share') * $stock),
        );

        return max(0, min($base, $cap));
    }

    /**
     * Остаток по primary-складам региона по умолчанию — как для гостей каталога
     * (StockService::resolveRegionId → Region::defaultId()).
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function defaultRegionStockMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $regionId = Region::defaultId();
        if ($regionId === null) {
            return [];
        }

        $warehouseIds = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->where('type', 'primary')
            ->pluck('warehouse_id');

        if ($warehouseIds->isEmpty()) {
            return [];
        }

        return DB::table('product_warehouse')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as total')
            ->pluck('total', 'product_id')
            ->mapWithKeys(fn ($total, $productId) => [(int) $productId => (int) $total])
            ->all();
    }
}

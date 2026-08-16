<?php

namespace App\Http\Controllers\Wms;

use App\Models\Product;
use App\Models\ProductStockBuffer;
use App\Models\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WMS-консоль «Страховой запас» (карточка buf-06).
 *
 * Кладовщик первым видит и брак на полке, и подозрительно старые экземпляры,
 * поэтому ручное управление буфером живёт у склада: пометка «придержи N шт»
 * (`manual_qty`) побеждает ночной расчёт. Сам расчётный буфер руками не
 * редактируется — он пересчитается ночью.
 */
class StockBufferController extends WmsController
{
    public function index(): Response
    {
        $buffers = ProductStockBuffer::query()
            ->with(['product.media', 'manualAuthor:id,name'])
            ->get()
            ->sortByDesc(fn (ProductStockBuffer $buffer) => $buffer->effectiveQty())
            ->values();

        $stock = $this->defaultRegionStockMap($buffers->pluck('product_id')->all());
        $totalRegionStock = $this->totalRegionStock();

        $rows = $buffers->map(function (ProductStockBuffer $buffer) use ($stock) {
            $productStock = $stock[$buffer->product_id] ?? 0;
            $hidden = min($buffer->effectiveQty(), $productStock);

            return [
                'id' => $buffer->id,
                'product_id' => $buffer->product_id,
                'name' => $buffer->product?->name,
                'sku' => $buffer->product?->sku,
                'photo' => $buffer->product?->getFirstMediaUrl('main', 'thumb') ?: null,
                'stock' => $productStock,
                'buffer_qty' => $buffer->buffer_qty,
                'manual_qty' => $buffer->manual_qty,
                'effective_qty' => $buffer->effectiveQty(),
                'hidden' => $hidden,
                'reasons' => $this->reasonLabels($buffer),
                'manual_author' => $buffer->manualAuthor?->name,
                'manual_set_at' => $buffer->manual_set_at?->format('d.m.Y H:i'),
                'base_price' => (float) ($buffer->product?->base_price ?? 0),
            ];
        });

        $hiddenUnits = $rows->sum('hidden');
        $hiddenAmount = $rows->sum(fn (array $row) => $row['hidden'] * $row['base_price']);

        return Inertia::render('Wms/Pages/StockBuffers/Index', [
            'rows' => $rows->values(),
            'summary' => [
                'hidden_units' => $hiddenUnits,
                'hidden_amount' => round($hiddenAmount, 2),
                'stock_share_pct' => $totalRegionStock > 0
                    ? round($hiddenUnits / $totalRegionStock * 100, 3)
                    : null,
                'enabled' => (bool) config('stock_buffer.enabled'),
            ],
            'cancellations' => $this->segmentCancellationsByMonth(),
        ]);
    }

    /**
     * Поиск товара для ручной пометки — по образцу непрерывного ввода
     * в остальных WMS-разделах.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $query = trim($request->string('query')->toString());

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $products = Product::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('code', 'like', "%{$query}%");
            })
            ->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$query])
            ->limit(10)
            ->get();

        $stock = $this->defaultRegionStockMap($products->pluck('id')->all());
        $existing = ProductStockBuffer::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->keyBy('product_id');

        return response()->json($products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'photo' => $product->getFirstMediaUrl('main', 'thumb') ?: null,
            'stock' => $stock[$product->id] ?? 0,
            'manual_qty' => $existing->get($product->id)?->manual_qty,
            'buffer_qty' => $existing->get($product->id)?->buffer_qty ?? 0,
        ]));
    }

    /**
     * Поставить или изменить ручную пометку «придержи N шт».
     */
    public function storeManual(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'manual_qty' => ['required', 'integer', 'min:0', 'max:1000'],
            ],
            [],
            ['product_id' => 'товар', 'manual_qty' => 'размер пометки'],
        );

        ProductStockBuffer::query()->updateOrCreate(
            ['product_id' => $validated['product_id']],
            [
                'manual_qty' => $validated['manual_qty'],
                'manual_set_by' => $request->user()->getKey(),
                'manual_set_at' => now(),
            ],
        );

        return back()->with('success', 'Ручная пометка сохранена');
    }

    /**
     * Снять ручную пометку: дальше действует расчётный буфер.
     *
     * Запись без сигналов удаляется целиком (отсутствие записи = 0),
     * с сигналами — остаётся с расчётным буфером.
     */
    public function clearManual(ProductStockBuffer $buffer): RedirectResponse
    {
        if ($buffer->buffer_qty === 0 && $buffer->reasons === null) {
            $buffer->delete();
        } else {
            $buffer->fill([
                'manual_qty' => null,
                'manual_set_by' => null,
                'manual_set_at' => null,
            ])->save();
        }

        return back()->with('success', 'Ручная пометка снята — действует расчётный буфер');
    }

    /**
     * Человекочитаемая раскладка «почему товар в списке».
     *
     * @return list<string>
     */
    private function reasonLabels(ProductStockBuffer $buffer): array
    {
        $labels = [];
        $reasons = $buffer->reasons ?? [];

        if (isset($reasons['cancellations'])) {
            $window = (int) config('stock_buffer.cancellations.window_days');
            $labels[] = "{$reasons['cancellations']} отмен за {$window} дн";
        }

        if (isset($reasons['defect_batches'])) {
            $labels[] = "{$reasons['defect_batches']} парт. брака";
        }

        if (isset($reasons['shelf_life'])) {
            $shelf = $buffer->product?->shelfLifeValue?->datetime_value;
            $labels[] = $shelf !== null
                ? 'срок до '.$shelf->format('m/y')
                : 'срок годности близко';
        }

        if ($buffer->manual_qty !== null) {
            $labels[] = 'ручная пометка';
        }

        return $labels;
    }

    /**
     * Доля заказов клиентов сегмента с отменёнными строками по месяцам —
     * главный показатель эпика: после включения буфера должен пойти вниз.
     *
     * @return list<array{month: string, orders: int, with_cancellations: int, pct: int|null}>
     */
    private function segmentCancellationsByMonth(int $months = 6): array
    {
        $since = now()->startOfMonth()->subMonths($months - 1)->toDateTimeString();

        // Бизнес-дата — COALESCE(erp_created_at, created_at), как во всей
        // аналитике. Выражение месяца диалектное: тесты бегут на SQLite,
        // прод — MySQL (проверено на локальной копии).
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', COALESCE(orders.erp_created_at, orders.created_at))"
            : "DATE_FORMAT(COALESCE(orders.erp_created_at, orders.created_at), '%Y-%m')";

        $rows = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->where('users.stock_buffer_enabled', true)
            ->whereNull('orders.deleted_at')
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) >= ?', [$since])
            ->groupByRaw($monthExpr)
            ->selectRaw(
                "{$monthExpr} as month,
                 COUNT(*) as orders,
                 SUM(EXISTS (
                     SELECT 1 FROM order_items
                     WHERE order_items.order_id = orders.id AND order_items.cancelled = 1
                 )) as with_cancellations",
            )
            ->orderBy('month')
            ->get();

        return $rows->map(fn ($row) => [
            'month' => (string) $row->month,
            'orders' => (int) $row->orders,
            'with_cancellations' => (int) $row->with_cancellations,
            'pct' => (int) $row->orders > 0
                ? (int) round((int) $row->with_cancellations / (int) $row->orders * 100)
                : null,
        ])->all();
    }

    /**
     * Остаток по primary-складам региона по умолчанию — как считает буфер.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function defaultRegionStockMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $warehouseIds = $this->defaultRegionPrimaryWarehouseIds();
        if ($warehouseIds === []) {
            return [];
        }

        return DB::table('product_warehouse')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as total')
            ->pluck('total', 'product_id')
            ->mapWithKeys(fn ($total, $id) => [(int) $id => (int) $total])
            ->all();
    }

    private function totalRegionStock(): int
    {
        $warehouseIds = $this->defaultRegionPrimaryWarehouseIds();
        if ($warehouseIds === []) {
            return 0;
        }

        return (int) DB::table('product_warehouse')
            ->whereIn('warehouse_id', $warehouseIds)
            ->sum('quantity');
    }

    /**
     * @return list<int>
     */
    private function defaultRegionPrimaryWarehouseIds(): array
    {
        $regionId = Region::defaultId();
        if ($regionId === null) {
            return [];
        }

        return DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->where('type', 'primary')
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

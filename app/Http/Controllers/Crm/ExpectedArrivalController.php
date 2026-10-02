<?php

namespace App\Http\Controllers\Crm;

use App\Models\Product;
use App\Models\ProductExpectedArrival;
use App\Models\ProductExpectedArrivalSnapshot;
use App\Models\Warehouse;
use App\Services\Stock\ExpectedArrivals;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * CRM-раздел «Ожидаемые поступления» (v16.16.0, топик №16 Agent Hub).
 *
 * Отвечает менеджеру на вопрос клиента «когда приедет?»: по товару и складу —
 * дата (или «дата уточняется») и количество к поступлению, как их ведут закупки
 * в 1С. Источник — снимки `product.expected_arrivals.updated`.
 *
 * Только для сотрудников: решение заказчика 01.10.2026 — клиентам (витрина,
 * кабинет, клиентский API) ожидания не показываются. Отдельного права нет:
 * раздел открыт тому же, кто видит заказы партнёров (`crm-clients.view`).
 */
class ExpectedArrivalController extends CrmController
{
    private const PER_PAGE = [25, 50, 100];

    public function index(Request $request, ExpectedArrivals $arrivals): InertiaResponse
    {
        $filters = $this->filters($request);
        $today = Carbon::today()->toDateString();

        // Ближайшая будущая дата по товару — порядок списка: что приедет раньше, то выше;
        // товары, у которых все ожидания «дата уточняется», идут в конце.
        $nearest = ProductExpectedArrival::query()
            ->select('product_id')
            ->selectRaw('MIN(CASE WHEN expected_date >= ? THEN expected_date END) as nearest_date', [$today])
            ->when($filters['warehouse_id'], fn ($query, $id) => $query->where('warehouse_id', $id))
            ->groupBy('product_id');

        $page = Product::withoutGlobalScopes()
            ->joinSub($nearest, 'arrivals', 'arrivals.product_id', '=', 'products.id')
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $like = '%'.addcslashes($filters['search'], '%_\\').'%';

                $query->where(fn ($inner) => $inner
                    ->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhere('products.barcode', 'like', $like));
            })
            ->with('brand:id,name')
            ->orderByRaw('arrivals.nearest_date IS NULL')
            ->orderBy('arrivals.nearest_date')
            ->orderBy('products.name')
            ->select('products.id', 'products.name', 'products.sku', 'products.slug', 'products.brand_id', 'products.hidden')
            ->paginate($filters['per_page'])
            ->withQueryString();

        $ids = collect($page->items())->pluck('id')->all();
        $byProduct = $arrivals->forProducts($ids);
        $stock = $this->freeStock($ids);

        $rows = $page->through(function (Product $product) use ($byProduct, $stock, $arrivals, $filters) {
            $warehouses = $byProduct[$product->id] ?? [];

            if ($filters['warehouse_id']) {
                $warehouses = array_values(array_filter(
                    $warehouses,
                    static fn (array $warehouse) => $warehouse['warehouse_id'] === $filters['warehouse_id'],
                ));
            }

            foreach ($warehouses as &$warehouse) {
                $warehouse['free'] = (int) ($stock[$product->id][$warehouse['warehouse_id']] ?? 0);
            }
            unset($warehouse);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'brand' => $product->brand?->name,
                // Скрытый товар на витрине не открывается — ссылку не даём.
                'slug' => $product->hidden ? null : $product->slug,
                'warehouses' => $warehouses,
                'summary' => $arrivals->summary($warehouses),
            ];
        });

        return Inertia::render('Crm/Pages/Arrivals/Index', [
            'rows' => $rows,
            'filters' => $filters,
            'warehouses' => Warehouse::query()
                ->whereIn('id', ProductExpectedArrival::query()->select('warehouse_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Warehouse $warehouse) => ['value' => $warehouse->id, 'label' => $warehouse->name])
                ->all(),
            // Когда сайт в последний раз применил снимок из 1С — чтобы менеджер
            // понимал свежесть данных, а пустой экран не выглядел поломкой.
            'updatedAt' => ($last = ProductExpectedArrivalSnapshot::query()->max('received_at')) !== null
                ? Carbon::parse($last)->format('d.m.Y H:i')
                : null,
        ]);
    }

    /**
     * @return array{search: string, warehouse_id: int|null, per_page: int}
     */
    private function filters(Request $request): array
    {
        $perPage = (int) $request->input('per_page', 50);
        $warehouseId = (int) $request->input('warehouse_id', 0);

        return [
            'search' => trim((string) $request->input('search', '')),
            'warehouse_id' => $warehouseId > 0 ? $warehouseId : null,
            'per_page' => in_array($perPage, self::PER_PAGE, true) ? $perPage : 50,
        ];
    }

    /**
     * Свободный остаток сейчас: товар → склад → штук (то, что несёт stock.updated).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, int>>
     */
    private function freeStock(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $stock = [];

        DB::table('product_warehouse')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'warehouse_id', 'quantity'])
            ->each(function ($row) use (&$stock) {
                $stock[(int) $row->product_id][(int) $row->warehouse_id] = (int) $row->quantity;
            });

        return $stock;
    }
}

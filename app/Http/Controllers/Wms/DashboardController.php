<?php

namespace App\Http\Controllers\Wms;

use App\Models\GoodsIssue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends WmsController
{
    public function index(Request $request): Response
    {
        $warehouses = $this->stockByWarehouse();

        return Inertia::render('Wms/Pages/Dashboard', [
            'warehouses' => $warehouses,
            'totals' => [
                'warehouses' => $warehouses->count(),
                'positions_in_stock' => $warehouses->sum('positions_in_stock'),
                'units_total' => $warehouses->sum('units_total'),
            ],
            'isWarehouseHead' => $this->isWarehouseHead($request),
            'goodsIssues' => $this->goodsIssueSummary($request),
        ]);
    }

    /**
     * Сводка по расходным ордерам: сколько в работе и сколько зависло.
     *
     * Показываем только тем, у кого есть право на раздел — иначе плитка вела бы
     * на 403. Пусто (null) — плитки нет вовсе.
     *
     * @return array{active: int, stale: int}|null
     */
    private function goodsIssueSummary(Request $request): ?array
    {
        if (! $request->user()?->can('wms-goods-issues.view')) {
            return null;
        }

        return [
            'active' => GoodsIssue::query()->whereIn('status', GoodsIssue::ACTIVE_STATUSES)->count(),
            'stale' => GoodsIssue::query()->stale()->count(),
        ];
    }

    /**
     * Остатки в разрезе складов — одним агрегатным запросом.
     *
     * Товары не джойним: у products нет soft-delete, а FK в product_warehouse
     * каскадный, поэтому осиротевших строк не бывает и цифры не завышаются.
     * Склады берём из warehouses, чтобы пустой склад тоже попал в выдачу.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, positions_total: int, positions_in_stock: int, positions_zero: int, units_total: int}>
     */
    private function stockByWarehouse(): \Illuminate\Support\Collection
    {
        return DB::table('warehouses')
            ->leftJoin('product_warehouse', 'product_warehouse.warehouse_id', '=', 'warehouses.id')
            ->selectRaw('
                warehouses.id,
                warehouses.name,
                COUNT(product_warehouse.id) as positions_total,
                COALESCE(SUM(CASE WHEN product_warehouse.quantity > 0 THEN 1 ELSE 0 END), 0) as positions_in_stock,
                COALESCE(SUM(CASE WHEN product_warehouse.quantity = 0 THEN 1 ELSE 0 END), 0) as positions_zero,
                COALESCE(SUM(product_warehouse.quantity), 0) as units_total
            ')
            ->groupBy('warehouses.id', 'warehouses.name')
            ->orderBy('warehouses.name')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'positions_total' => (int) $row->positions_total,
                'positions_in_stock' => (int) $row->positions_in_stock,
                'positions_zero' => (int) $row->positions_zero,
                'units_total' => (int) $row->units_total,
            ]);
    }
}

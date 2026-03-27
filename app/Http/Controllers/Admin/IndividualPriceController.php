<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IndividualPrice;
use App\Models\User;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IndividualPriceController extends Controller
{
    public function index(Request $request)
    {
        $query = IndividualPrice::query()
            ->join('users', 'individual_prices.partner_id', '=', 'users.id')
            ->join('products', 'individual_prices.product_id', '=', 'products.id')
            ->join('warehouses', 'individual_prices.warehouse_id', '=', 'warehouses.id')
            ->select([
                'individual_prices.partner_id',
                'individual_prices.product_id',
                'individual_prices.warehouse_id',
                'individual_prices.price',
                'individual_prices.updated_at',
                'users.name as partner_name',
                'users.email as partner_email',
                'products.name as product_name',
                'products.sku as product_sku',
                'warehouses.name as warehouse_name',
            ]);

        // Фильтр по партнёру
        if ($request->filled('partner_id')) {
            $query->where('individual_prices.partner_id', $request->input('partner_id'));
        }

        // Фильтр по товару
        if ($request->filled('product_id')) {
            $query->where('individual_prices.product_id', $request->input('product_id'));
        }

        // Фильтр по складу
        if ($request->filled('warehouse_id')) {
            $query->where('individual_prices.warehouse_id', $request->input('warehouse_id'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'updated_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSorts = ['price', 'updated_at', 'partner_name', 'product_name', 'warehouse_name'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $prices = $query->paginate($request->input('per_page', 25));

        // Статистика
        $stats = [
            'total_prices' => IndividualPrice::count(),
            'total_partners' => IndividualPrice::distinct('partner_id')->count('partner_id'),
        ];

        return Inertia::render('Admin/Pages/IndividualPrices/Index', [
            'prices' => $prices,
            'filters' => $request->only(['partner_id', 'product_id', 'warehouse_id', 'sort_by', 'sort_order', 'per_page']),
            'stats' => $stats,
            // Передаём начальные display-тексты для EntitySelector (при reload страницы с фильтрами)
            'filterLabels' => $this->getFilterLabels($request),
        ]);
    }

    /**
     * Async search: партнёры
     */
    public function searchPartners(Request $request)
    {
        $query = User::query()
            ->whereIn('id', function ($q) {
                $q->select('partner_id')->from('individual_prices')->distinct();
            });

        if ($search = $request->input('query')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->select('id', 'name', 'surname', 'email')
                ->limit(20)
                ->get()
                ->map(fn($u) => [
                    'id' => $u->id,
                    'label' => $u->full_name,
                    'email' => $u->email,
                ])
        );
    }

    /**
     * Async search: товары
     */
    public function searchProducts(Request $request)
    {
        $query = Product::query()
            ->whereIn('id', function ($q) {
                $q->select('product_id')->from('individual_prices')->distinct();
            });

        if ($search = $request->input('query')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->select('id', 'name', 'sku')
                ->limit(20)
                ->get()
                ->map(fn($p) => [
                    'id' => $p->id,
                    'label' => "{$p->sku} — {$p->name}",
                    'name' => $p->name,
                    'sku' => $p->sku,
                ])
        );
    }

    /**
     * Async search: склады
     */
    public function searchWarehouses(Request $request)
    {
        $query = Warehouse::query()
            ->whereIn('id', function ($q) {
                $q->select('warehouse_id')->from('individual_prices')->distinct();
            });

        if ($search = $request->input('query')) {
            $query->where('name', 'like', "%{$search}%");
        }

        return response()->json(
            $query->select('id', 'name')
                ->limit(20)
                ->get()
                ->map(fn($w) => [
                    'id' => $w->id,
                    'label' => $w->name,
                ])
        );
    }

    /**
     * CSV экспорт с текущими фильтрами
     */
    public function export(Request $request): StreamedResponse
    {
        $query = IndividualPrice::query()
            ->join('users', 'individual_prices.partner_id', '=', 'users.id')
            ->join('products', 'individual_prices.product_id', '=', 'products.id')
            ->join('warehouses', 'individual_prices.warehouse_id', '=', 'warehouses.id')
            ->select([
                'users.name as partner_name',
                'users.email as partner_email',
                'products.name as product_name',
                'products.sku as product_sku',
                'warehouses.name as warehouse_name',
                'individual_prices.price',
                'individual_prices.updated_at',
            ]);

        if ($request->filled('partner_id')) {
            $query->where('individual_prices.partner_id', $request->input('partner_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('individual_prices.product_id', $request->input('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('individual_prices.warehouse_id', $request->input('warehouse_id'));
        }

        $query->orderBy('individual_prices.updated_at', 'desc');

        $filename = 'individual_prices_' . now()->format('Y-m-d_H-i') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM для Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // Заголовок
            fputcsv($handle, ['Партнёр', 'Email', 'Товар', 'Артикул', 'Склад', 'Цена', 'Обновлено'], ';');

            $query->chunk(1000, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->partner_name,
                        $row->partner_email,
                        $row->product_name,
                        $row->product_sku,
                        $row->warehouse_name,
                        number_format($row->price, 2, ',', ''),
                        $row->updated_at?->format('d.m.Y H:i:s'),
                    ], ';');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Получить display-тексты для фильтров (для начального рендера при URL-навигации)
     */
    private function getFilterLabels(Request $request): array
    {
        $labels = [];

        if ($request->filled('partner_id')) {
            $user = User::find($request->input('partner_id'));
            $labels['partner'] = $user ? ($user->full_name ?? $user->name) : null;
        }

        if ($request->filled('product_id')) {
            $product = Product::find($request->input('product_id'));
            $labels['product'] = $product ? "{$product->sku} — {$product->name}" : null;
        }

        if ($request->filled('warehouse_id')) {
            $warehouse = Warehouse::find($request->input('warehouse_id'));
            $labels['warehouse'] = $warehouse?->name;
        }

        return $labels;
    }
}

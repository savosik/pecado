<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Http\Controllers\Controller;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pricing\IndividualPriceStatsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IndividualPriceController extends Controller
{
    use RedirectsAfterSave;

    public function __construct(
        private readonly IndividualPriceStatsService $statsService
    ) {}

    public function index(Request $request)
    {
        $query = IndividualPrice::query();

        // Фильтр по партнёру
        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->input('partner_id'));
        }

        // Фильтр по товару
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        // Фильтр по складу
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        // Сортировка — только по полям базовой таблицы для использования индексов
        $sortBy = $request->input('sort_by', 'updated_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSorts = ['price', 'updated_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('updated_at', 'desc');
        }

        $prices = $query->simplePaginate($request->input('per_page', 25))->withQueryString();

        // Подгружаем имена связанных сущностей только для уже отпагинированных записей
        $partnerIds = $prices->pluck('partner_id')->unique();
        $productIds = $prices->pluck('product_id')->unique();
        $warehouseIds = $prices->pluck('warehouse_id')->unique();

        // Один запрос вместо двух (name + email)
        $partnerData = User::whereIn('id', $partnerIds)->get(['id', 'name', 'email'])->keyBy('id');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
        $warehouses = Warehouse::whereIn('id', $warehouseIds)->pluck('name', 'id')->toArray();

        // Трансформируем данные для фронтенда
        $prices->through(function ($item) use ($partnerData, $products, $warehouses) {
            $partner = $partnerData[$item->partner_id] ?? null;
            $product = $products[$item->product_id] ?? null;
            $item->partner_name = $partner?->name ?? "ID: {$item->partner_id}";
            $item->partner_email = $partner?->email ?? '';
            $item->product_name = $product?->name ?? "ID: {$item->product_id}";
            $item->product_sku = $product?->sku ?? '';
            $item->warehouse_name = $warehouses[$item->warehouse_id] ?? "ID: {$item->warehouse_id}";

            return $item;
        });

        $stats = $this->statsService->get();

        return Inertia::render('Admin/Pages/IndividualPrices/Index', [
            'prices' => $prices,
            'filters' => $request->only(['partner_id', 'product_id', 'warehouse_id', 'sort_by', 'sort_order', 'per_page']),
            'stats' => $stats,
            'filterLabels' => $this->getFilterLabels($request),
        ]);
    }

    /**
     * Форма создания
     */
    public function create()
    {
        return Inertia::render('Admin/Pages/IndividualPrices/Create');
    }

    /**
     * Сохранение новой цены
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'partner_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'price' => 'required|numeric|min:0',
        ], [
            'partner_id.required' => 'Выберите партнёра',
            'partner_id.exists' => 'Партнёр не найден',
            'product_id.required' => 'Выберите товар',
            'product_id.exists' => 'Товар не найден',
            'warehouse_id.required' => 'Выберите склад',
            'warehouse_id.exists' => 'Склад не найден',
            'price.required' => 'Укажите цену',
            'price.numeric' => 'Цена должна быть числом',
            'price.min' => 'Цена не может быть отрицательной',
        ]);

        // Upsert — composite PK: partner_id + product_id + warehouse_id
        IndividualPrice::upsert(
            [$validated],
            ['partner_id', 'product_id', 'warehouse_id'],
            ['price']
        );

        $this->statsService->forget();

        return redirect()->route('admin.individual-prices.index', [
            'partner_id' => $validated['partner_id'],
        ])->with('success', 'Индивидуальная цена сохранена');
    }

    /**
     * Форма редактирования
     */
    public function edit(Request $request)
    {
        $request->validate([
            'partner_id' => 'required|integer',
            'product_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
        ]);

        $price = IndividualPrice::where('partner_id', $request->partner_id)
            ->where('product_id', $request->product_id)
            ->where('warehouse_id', $request->warehouse_id)
            ->firstOrFail();

        $partner = User::find($price->partner_id);
        $product = Product::find($price->product_id);
        $warehouse = Warehouse::find($price->warehouse_id);

        return Inertia::render('Admin/Pages/IndividualPrices/Edit', [
            'individualPrice' => [
                'partner_id' => $price->partner_id,
                'product_id' => $price->product_id,
                'warehouse_id' => $price->warehouse_id,
                'price' => $price->price,
                'updated_at' => $price->updated_at,
            ],
            'labels' => [
                'partner' => $partner ? ($partner->name ?? $partner->name) : "ID: {$price->partner_id}",
                'product' => $product ? "{$product->sku} — {$product->name}" : "ID: {$price->product_id}",
                'warehouse' => $warehouse?->name ?? "ID: {$price->warehouse_id}",
            ],
        ]);
    }

    /**
     * Обновление цены
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'partner_id' => 'required|integer',
            'product_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
            'price' => 'required|numeric|min:0',
        ], [
            'price.required' => 'Укажите цену',
            'price.numeric' => 'Цена должна быть числом',
            'price.min' => 'Цена не может быть отрицательной',
        ]);

        IndividualPrice::where('partner_id', $validated['partner_id'])
            ->where('product_id', $validated['product_id'])
            ->where('warehouse_id', $validated['warehouse_id'])
            ->update(['price' => $validated['price']]);

        $this->statsService->forget();

        return redirect()->route('admin.individual-prices.index', [
            'partner_id' => $validated['partner_id'],
        ])->with('success', 'Цена обновлена');
    }

    /**
     * Удаление цены
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'partner_id' => 'required|integer',
            'product_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
        ]);

        IndividualPrice::where('partner_id', $request->partner_id)
            ->where('product_id', $request->product_id)
            ->where('warehouse_id', $request->warehouse_id)
            ->delete();

        $this->statsService->forget();

        return redirect()->back()->with('success', 'Цена удалена');
    }

    /**
     * Async search: партнёры (все пользователи для создания)
     */
    public function searchPartners(Request $request)
    {
        $query = User::query();

        // При создании — ищем среди всех; при фильтрации — только тех, у кого есть цены
        if ($request->boolean('only_with_prices')) {
            $query->whereIn('id', $this->statsService->getDistinctPartnerIds());
        }

        if ($search = $request->input('query')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->select('id', 'name', 'email')
                ->limit(20)
                ->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'label' => $u->name,
                    'email' => $u->email,
                ])
        );
    }

    /**
     * Async search: товары
     */
    public function searchProducts(Request $request)
    {
        $query = Product::query();

        if ($request->boolean('only_with_prices')) {
            $query->whereIn('id', $this->statsService->getDistinctProductIds());
        }

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
                ->map(fn ($p) => [
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
        $query = Warehouse::query();

        if ($request->boolean('only_with_prices')) {
            $query->whereIn('id', $this->statsService->getDistinctWarehouseIds());
        }

        if ($search = $request->input('query')) {
            $query->where('name', 'like', "%{$search}%");
        }

        return response()->json(
            $query->select('id', 'name')
                ->limit(20)
                ->get()
                ->map(fn ($w) => [
                    'id' => $w->id,
                    'label' => $w->name,
                ])
        );
    }

    /**
     * CSV экспорт — только при наличии фильтра partner_id или product_id
     */
    public function export(Request $request): StreamedResponse
    {
        if (! $request->filled('partner_id') && ! $request->filled('product_id')) {
            abort(422, 'Для экспорта необходимо выбрать партнёра или товар');
        }

        // individual_prices живёт в отдельной prices DB — JOIN с main DB невозможен.
        // Подгружаем цены из prices DB, имена сущностей — из main DB отдельными запросами.
        $query = DB::connection('prices')->table('individual_prices');

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->input('partner_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        $query->orderBy('updated_at', 'desc');

        $filename = 'individual_prices_'.now()->format('Y-m-d_H-i').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM для Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // Заголовок
            fputcsv($handle, ['Партнёр', 'Email', 'Товар', 'Артикул', 'Склад', 'Цена', 'Обновлено'], ';');

            $query->chunk(1000, function ($rows) use ($handle) {
                $partnerIds = $rows->pluck('partner_id')->unique();
                $productIds = $rows->pluck('product_id')->unique();
                $warehouseIds = $rows->pluck('warehouse_id')->unique();

                $partners = User::whereIn('id', $partnerIds)->get()->keyBy('id');
                $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
                $warehouses = Warehouse::whereIn('id', $warehouseIds)->pluck('name', 'id');

                foreach ($rows as $row) {
                    $partner = $partners[$row->partner_id] ?? null;
                    $product = $products[$row->product_id] ?? null;
                    fputcsv($handle, [
                        $partner?->name ?? "ID: {$row->partner_id}",
                        $partner?->email ?? '',
                        $product?->name ?? "ID: {$row->product_id}",
                        $product?->sku ?? '',
                        $warehouses[$row->warehouse_id] ?? "ID: {$row->warehouse_id}",
                        number_format((float) $row->price, 2, ',', ''),
                        $row->updated_at ? Carbon::parse($row->updated_at)->format('d.m.Y H:i:s') : '',
                    ], ';');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Получить display-тексты для фильтров
     */
    private function getFilterLabels(Request $request): array
    {
        $labels = [];

        if ($request->filled('partner_id')) {
            $user = User::find($request->input('partner_id'));
            $labels['partner'] = $user ? ($user->name ?? $user->name) : null;
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

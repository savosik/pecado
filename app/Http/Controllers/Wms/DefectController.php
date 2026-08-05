<?php

namespace App\Http\Controllers\Wms;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Enums\DefectClosedReason;
use App\Http\Requests\Wms\StoreProductDefectRequest;
use App\Http\Requests\Wms\UpdateProductDefectRequest;
use App\Models\DefectType;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\Warehouse;
use App\Services\Defect\DefectCoverageService;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Учёт некондиции кладовщиком.
 *
 * Кладовщик заводит партии (дефект + количество + фото) и правит их.
 * Цену и публикацию на сайте задаёт закупщик в /admin — здесь они только
 * отображаются, чтобы кладовщик видел, что с партией уже произошло.
 */
class DefectController extends WmsController
{
    public function __construct(
        private readonly DefectStockServiceInterface $defectStock,
        private readonly DefectCoverageService $defectCoverage
    ) {}

    public function index(Request $request): Response
    {
        $filter = $request->string('filter')->toString();

        $defects = ProductDefect::query()
            ->with(['product:id,name,sku', 'warehouse:id,name', 'media', 'creator:id,name'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->string('search')->toString());

                $query->where(function ($q) use ($search) {
                    $q->where('defect_description', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                        );
                });
            })
            ->when($filter === 'open', fn ($q) => $q->whereNull('closed_at'))
            ->when($filter === 'closed', fn ($q) => $q->whereNotNull('closed_at'))
            ->when($filter === 'unpriced', fn ($q) => $q->whereNull('closed_at')->whereNull('price'))
            ->when($filter === 'published', fn ($q) => $q->whereNull('closed_at')->where('is_published', true))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $available = $this->defectStock->availableMap($defects->getCollection());

        return Inertia::render('Wms/Pages/Defects/Index', [
            // through() сохраняет мета-данные пагинации и подменяет только элементы.
            'defects' => $defects->through(
                fn (ProductDefect $defect) => $this->presentDefect($defect, $available)
            ),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'filter' => $filter,
            ],
            'stats' => $this->stats(),
            'isWarehouseHead' => $this->isWarehouseHead($request),
        ]);
    }

    /**
     * Остатки склада некондиции, не закрытые партиями.
     *
     * Уценка попадает на витрину только через партию: пока кладовщик её не
     * завёл, остаток из 1С висит на складе и никому не предлагается. Здесь
     * видно потоварно, сколько такого остатка и по каким артикулам, а заодно
     * обратные расхождения — партий заведено больше, чем числится в 1С.
     */
    public function uncovered(Request $request): Response
    {
        $filter = $request->string('filter')->toString() ?: DefectCoverageService::FILTER_UNCOVERED;
        $search = trim($request->string('search')->toString());
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $rows = $this->defectCoverage
            ->rows($filter, $search ?: null, $warehouseId)
            // При расхождении интереснее самые «минусовые» позиции, в остальных
            // случаях — самый большой непокрытый остаток.
            ->orderBy(
                'uncovered_quantity',
                $filter === DefectCoverageService::FILTER_OVER ? 'asc' : 'desc'
            )
            ->orderBy('product_name')
            ->paginate(30)
            ->withQueryString()
            ->through($this->presentCoverageRow(...));

        return Inertia::render('Wms/Pages/Defects/Uncovered', [
            'rows' => $rows,
            'filters' => [
                'filter' => $filter,
                'search' => $search,
                'warehouse_id' => $warehouseId,
            ],
            'warehouses' => $this->defectCoverage->warehouses(),
            'stats' => $this->defectCoverage->stats($search ?: null, $warehouseId),
        ]);
    }

    /**
     * @param  object  $row  Строка отчёта покрытия (сырой результат запроса)
     * @return array<string, mixed>
     */
    private function presentCoverageRow(object $row): array
    {
        return [
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'product_sku' => $row->product_sku,
            'warehouse_id' => (int) $row->warehouse_id,
            'warehouse_name' => $row->warehouse_name,
            'stock_quantity' => (int) $row->stock_quantity,
            'covered_quantity' => (int) $row->covered_quantity,
            'idle_quantity' => (int) $row->idle_quantity,
            'batches_count' => (int) $row->batches_count,
            'uncovered_quantity' => (int) $row->uncovered_quantity,
        ];
    }

    /**
     * Список «К отгрузке»: заказы уценки, переведённые в «Готов к отгрузке».
     *
     * Комплектовщик не знает из 1С, какой именно дефектный экземпляр отдать —
     * поэтому здесь по каждому заказу видно, какую партию (с дефектом и фото)
     * отгрузить. Пока заказ в этом статусе, партия числится в резерве и с витрины
     * скрыта — снять резерв может только удаление заказа.
     */
    public function shipping(Request $request): Response
    {
        $orders = \App\Models\Order::query()
            ->where('type', \App\Enums\OrderType::DEFECT->value)
            ->where('status', \App\Enums\OrderStatus::READY_FOR_SHIPMENT->value)
            ->with([
                'user:id,name',
                'items' => fn ($q) => $q->whereNotNull('product_defect_id'),
                'items.product:id,name,sku',
                // Партию грузим вместе с мягко удалёнными: если её удалили после
                // формирования заказа, позиция всё равно должна остаться видимой
                // (на фронте — серой/disabled), а не превратиться в пустую строку.
                'items.productDefect' => fn ($q) => $q->withTrashed(),
                'items.productDefect.media',
            ])
            ->latest('id')
            ->paginate(20)
            ->through($this->presentShippingOrder(...));

        return Inertia::render('Wms/Pages/Defects/Shipping', [
            'orders' => $orders,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentShippingOrder(\App\Models\Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'erp_number' => $order->erp_number,
            'created_at' => $order->created_at?->toIso8601String(),
            'client_name' => $order->user?->name,
            'warehouse_comment' => $order->warehouse_comment,
            'items' => $order->items->map(fn (\App\Models\OrderItem $item) => [
                'id' => $item->id,
                'product_defect_id' => $item->product_defect_id,
                'product_name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'quantity' => $item->quantity,
                // Партия уценки мягко удалена (или удалена совсем) — позицию
                // показываем, но помечаем как неактивную.
                'defect_deleted' => $item->productDefect === null
                    || $item->productDefect->trashed(),
                // Снапшот дефекта на момент заказа — приоритетнее текущего описания партии.
                'defect_description' => $item->defect_description
                    ?: $item->productDefect?->defect_description,
                'photos' => $item->productDefect
                    ? $item->productDefect->getMedia(ProductDefect::MEDIA_COLLECTION)->map(fn ($media) => [
                        'id' => $media->id,
                        'url' => $media->getUrl(),
                        'thumb_url' => $media->hasGeneratedConversion('thumb')
                            ? $media->getUrl('thumb')
                            : $media->getUrl(),
                    ])->values()
                    : collect(),
            ])->values(),
        ];
    }

    /**
     * Справочник кодов дефектов (read-only) для комплектовщика.
     *
     * В печатном документе 1С дефекты перечислены кодами (id типа дефекта) —
     * эта таблица служит легендой: код → формулировка. Выгружается в Excel,
     * чтобы комплектовщик держал её под рукой при отборе некондиции.
     */
    public function codes(): Response
    {
        return Inertia::render('Wms/Pages/Defects/Codes', [
            'codes' => $this->defectCodeRows(),
        ]);
    }

    /**
     * Выгрузка справочника кодов дефектов в XLSX.
     */
    public function codesExport(SimpleXlsxExporter $exporter): StreamedResponse
    {
        $rows = collect($this->defectCodeRows())->map(fn (array $row) => [
            $row['id'],
            $row['name'],
            $row['is_active'] ? 'да' : 'нет',
        ]);

        return $exporter->stream(
            'defect-codes-'.now()->format('Y-m-d'),
            ['Код', 'Дефект', 'Активен'],
            $rows,
            'Коды дефектов',
        );
    }

    /**
     * Все типы дефектов легендой: код (id) → формулировка. Отсортированы по
     * коду — чтобы «[5]» из документа было легко найти. Неактивные включены:
     * старые партии могут ссылаться на них, документ всё равно нужно расшифровать.
     *
     * @return array<int, array{id: int, name: string, is_active: bool}>
     */
    private function defectCodeRows(): array
    {
        return DefectType::query()
            ->orderBy('id')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (DefectType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'is_active' => (bool) $type->is_active,
            ])
            ->all();
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Wms/Pages/Defects/Create', [
            'warehouses' => $this->defectWarehouses(),
            'defectTypes' => $this->defectTypeChips(),
            'prefill' => $this->createPrefill($request),
        ]);
    }

    /**
     * Предзаполнение формы из отчёта о непокрытых остатках: кладовщик переходит
     * по строке «остаток есть, партии нет» и сразу видит товар и склад.
     *
     * @return array{product: array<string, mixed>, warehouse_id: int}|null
     */
    private function createPrefill(Request $request): ?array
    {
        $productId = $request->integer('product_id');

        if (! $productId) {
            return null;
        }

        $product = Product::query()->with('media')->find($productId);

        if (! $product) {
            return null;
        }

        $warehouseId = $request->integer('warehouse_id');
        $warehouseId = Warehouse::query()->defect()->whereKey($warehouseId)->value('id');

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main'),
                'defect_stock' => (object) ($this->defectStockMap([(int) $product->id])[(int) $product->id] ?? []),
            ],
            'warehouse_id' => (int) $warehouseId,
        ];
    }

    /**
     * Активные типы дефектов для чипов быстрого выбора.
     *
     * Отдаём id вместе с именем: id типа дефекта показываем на чипах для сверки
     * с печатным документом 1С (там дефекты перечислены через id).
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function defectTypeChips(): array
    {
        return \App\Models\DefectType::query()
            ->active()
            ->ordered()
            ->get(['id', 'name'])
            ->map(fn (\App\Models\DefectType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->all();
    }

    public function store(StoreProductDefectRequest $request): RedirectResponse
    {
        $defect = DB::transaction(function () use ($request) {
            $defect = ProductDefect::create([
                'product_id' => $request->integer('product_id'),
                'warehouse_id' => $request->integer('warehouse_id'),
                'defect_description' => $request->string('defect_description')->toString(),
                'quantity' => $request->integer('quantity'),
                'created_by' => $request->user()->id,
            ]);

            $this->attachPhotos($defect, $request);

            return $defect;
        });

        $warning = $this->stockWarning(
            (int) $defect->product_id,
            (int) $defect->warehouse_id,
            (int) $defect->quantity
        );

        return redirect()
            ->route('wms.defects.edit', $defect)
            ->with('success', 'Партия некондиции заведена. Цену назначит закупщик.')
            ->with('warning', $warning);
    }

    /**
     * Экран быстрого приёма: сканируешь штрихкод камерой → дефект чипом → фото → дальше.
     */
    public function quick(): Response
    {
        return Inertia::render('Wms/Pages/Defects/Quick', [
            'warehouses' => $this->defectWarehouses(),
            'defectTypes' => $this->defectTypeChips(),
        ]);
    }

    /**
     * Найти товар по штрихкоду для быстрого приёма (камера/сканер/ручной ввод).
     */
    public function resolveBarcode(Request $request): JsonResponse
    {
        $barcode = trim($request->string('barcode')->toString());

        if ($barcode === '') {
            return response()->json(['found' => false], 200);
        }

        // Основной штрихкод товара или один из дополнительных.
        $product = Product::query()
            ->where('barcode', $barcode)
            ->orWhereHas('barcodes', fn ($q) => $q->where('barcode', $barcode))
            ->with('media')
            ->first();

        if (! $product) {
            return response()->json(['found' => false], 200);
        }

        return response()->json([
            'found' => true,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main'),
                'defect_stock' => (object) ($this->defectStockMap([(int) $product->id])[(int) $product->id] ?? []),
            ],
        ]);
    }

    /**
     * Сохранить партию из быстрого приёма — JSON-ответ, чтобы не перезагружать
     * камеру и состояние экрана. Фото здесь обязательны (см. QuickStoreProductDefectRequest).
     */
    public function quickStore(\App\Http\Requests\Wms\QuickStoreProductDefectRequest $request): JsonResponse
    {
        $defect = DB::transaction(function () use ($request) {
            $defect = ProductDefect::create([
                'product_id' => $request->integer('product_id'),
                'warehouse_id' => $request->integer('warehouse_id'),
                'defect_description' => $request->string('defect_description')->toString(),
                'quantity' => $request->integer('quantity'),
                'created_by' => $request->user()->id,
            ]);

            $this->attachPhotos($defect, $request);

            return $defect;
        });

        return response()->json([
            'success' => true,
            'message' => 'Партия принята.',
            'defect_id' => $defect->id,
            'warning' => $this->stockWarning(
                (int) $defect->product_id,
                (int) $defect->warehouse_id,
                (int) $defect->quantity
            ),
        ], 201);
    }

    public function edit(Request $request, ProductDefect $defect): Response
    {
        $defect->load(['product:id,name,sku', 'warehouse:id,name', 'media', 'creator:id,name', 'pricer:id,name']);

        return Inertia::render('Wms/Pages/Defects/Edit', [
            'defect' => $this->presentDefect($defect, [
                $defect->id => $this->defectStock->available($defect),
            ]),
            'reserved' => $this->defectStock->reserved($defect),
            'defectTypes' => $this->defectTypeChips(),
        ]);
    }

    public function update(UpdateProductDefectRequest $request, ProductDefect $defect): RedirectResponse
    {
        DB::transaction(function () use ($request, $defect) {
            $defect->update([
                'defect_description' => $request->string('defect_description')->toString(),
                'quantity' => $request->integer('quantity'),
            ]);

            foreach ($request->input('removed_media_ids', []) as $mediaId) {
                // Ограничиваем удаление медиа самой партией, чтобы по чужому id
                // нельзя было снести картинку другой сущности.
                $defect->media()->whereKey($mediaId)->first()?->delete();
            }

            $this->attachPhotos($defect, $request);
        });

        return back()->with('success', 'Партия обновлена.');
    }

    /**
     * Списание партии: физически брак утилизирован или пересортица.
     *
     * Полное удаление запрещено при наличии резерва — иначе позиции чужого
     * заказа потеряли бы ссылку на дефект.
     */
    public function writeOff(Request $request, ProductDefect $defect): RedirectResponse
    {
        if (! $request->user()->can('wms-defects.delete')) {
            abort(403);
        }

        if ($defect->isClosed()) {
            return back()->with('error', 'Партия уже закрыта.');
        }

        if ($this->defectStock->reserved($defect) > 0) {
            return back()->with('error', 'Партию нельзя списать: по ней есть заказы. Сначала отмените их.');
        }

        $defect->close(DefectClosedReason::WRITTEN_OFF);

        return back()->with('success', 'Партия списана.');
    }

    public function destroy(Request $request, ProductDefect $defect): RedirectResponse
    {
        if (! $request->user()->can('wms-defects.delete')) {
            abort(403);
        }

        if ($this->defectStock->reserved($defect) > 0) {
            return back()->with('error', 'Партию нельзя удалить: по ней есть заказы. Сначала отмените их.');
        }

        $defect->delete();

        return redirect()
            ->route('wms.defects.index')
            ->with('success', 'Партия удалена.');
    }

    /**
     * Поиск товара для формы заведения партии.
     *
     * Свой, а не админский `admin.products.search`: тот закрыт правом products.view,
     * которого у кладовщика нет и быть не должно. Кабинетный поиск тоже не подходит —
     * он считает «куплено» под конкретного покупателя.
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
                    ->orWhere('code', 'like', "%{$query}%")
                    ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'like', "%{$query}%"));
            })
            ->with(['brand:id,name', 'media', 'barcodes'])
            ->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$query])
            ->limit(15)
            ->get();

        $stock = $this->defectStockMap($products->pluck('id')->map(fn ($id) => (int) $id)->all());

        $products = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'brand_name' => $product->brand?->name,
            'image_url' => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main'),
            // barcode/barcodes нужны ProductSelector для автовыбора по сканеру:
            // кладовщик пикает штрихкод и сразу получает выбранный товар.
            'barcode' => $product->barcode,
            'barcodes' => $product->barcodes->pluck('barcode')->all(),
            // Остаток по складам некондиции — форма предупреждает, если товара там нет.
            // Объект, а не число: складов некондиции может быть несколько.
            'defect_stock' => (object) ($stock[(int) $product->id] ?? []),
        ]);

        return response()->json($products);
    }

    /**
     * Остатки товаров на складах некондиции по данным 1С.
     *
     * Нужны для предупреждения кладовщику: если брак ещё не оприходован на склад
     * некондиции, партию заводить рано — на витрине она встанет поверх нулевого
     * остатка, а 1С при отгрузке заказ не подтвердит.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, int>> [product_id => [warehouse_id => quantity]]
     */
    private function defectStockMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('product_warehouse')
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', Warehouse::query()->defect()->select('id'))
            ->get(['product_id', 'warehouse_id', 'quantity']);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->product_id][(int) $row->warehouse_id] = (int) $row->quantity;
        }

        return $result;
    }

    /**
     * Текст предупреждения о нехватке остатка под заводимую партию — или null,
     * если остатка хватает.
     *
     * Дублирует подсказку формы намеренно: форма предупреждает до сохранения,
     * этот флеш — после, чтобы предупреждение не потерялось, если кладовщик
     * заполнял партию до выбора склада.
     */
    private function stockWarning(int $productId, int $warehouseId, int $quantity): ?string
    {
        $warehouse = Warehouse::query()->defect()->find($warehouseId);

        if (! $warehouse) {
            return null;
        }

        $available = $this->defectStockMap([$productId])[$productId][$warehouseId] ?? 0;

        if ($available <= 0) {
            return "Внимание: на складе «{$warehouse->name}» этого товара нет в остатках. "
                .'Проверьте, оприходован ли брак на склад некондиции в 1С.';
        }

        if ($available < $quantity) {
            return "Внимание: на складе «{$warehouse->name}» числится {$available} шт., "
                ."а в партии {$quantity} шт. Проверьте остатки в 1С.";
        }

        return null;
    }

    /**
     * Склады, на которых можно вести некондицию.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string}>
     */
    private function defectWarehouses(): \Illuminate\Support\Collection
    {
        return Warehouse::query()
            ->defect()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
            ]);
    }

    /**
     * @return array{total: int, open: int, unpriced: int, published: int}
     */
    private function stats(): array
    {
        $row = ProductDefect::query()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN closed_at IS NULL THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN closed_at IS NULL AND price IS NULL THEN 1 ELSE 0 END) as unpriced,
                SUM(CASE WHEN closed_at IS NULL AND is_published = 1 THEN 1 ELSE 0 END) as published
            ')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'open' => (int) ($row->open ?? 0),
            'unpriced' => (int) ($row->unpriced ?? 0),
            'published' => (int) ($row->published ?? 0),
        ];
    }

    /**
     * @param  array<int, int>  $availableMap
     * @return array<string, mixed>
     */
    private function presentDefect(ProductDefect $defect, array $availableMap): array
    {
        return [
            'id' => $defect->id,
            'uuid' => $defect->uuid,
            'defect_description' => $defect->defect_description,
            'quantity' => $defect->quantity,
            'available_quantity' => $availableMap[$defect->id] ?? 0,
            'reserved_quantity' => $defect->quantity - ($availableMap[$defect->id] ?? 0),
            'price' => $defect->price !== null ? (float) $defect->price : null,
            'is_published' => $defect->is_published,
            'closed_at' => $defect->closed_at?->toIso8601String(),
            'closed_reason' => $defect->closed_reason?->value,
            'closed_reason_label' => $defect->closed_reason?->label(),
            'created_at' => $defect->created_at?->toIso8601String(),
            'created_by_name' => $defect->creator?->name,
            'priced_by_name' => $defect->pricer?->name,
            'product' => [
                'id' => $defect->product->id,
                'name' => $defect->product->name,
                'sku' => $defect->product->sku,
            ],
            'warehouse' => [
                'id' => $defect->warehouse->id,
                'name' => $defect->warehouse->name,
            ],
            'photos' => $defect->getMedia(ProductDefect::MEDIA_COLLECTION)->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
                'name' => $media->file_name,
            ])->values(),
        ];
    }

    private function attachPhotos(ProductDefect $defect, Request $request): void
    {
        foreach ($request->file('photos', []) as $photo) {
            $defect->addMedia($photo)->toMediaCollection(ProductDefect::MEDIA_COLLECTION);
        }
    }
}

<?php

namespace App\Http\Controllers\Wms;

use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\Warehouse;
use App\Services\SimpleXlsxExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Журнал расходных ордеров на товары (US-20).
 *
 * Read-only: документ принадлежит 1С, статусами управляет она же. Склад здесь смотрит,
 * фильтрует и выгружает — менять нечего, поэтому у ресурса `wms-goods-issues` есть
 * только права `view` и `export`.
 */
class GoodsIssueController extends WmsController
{
    /**
     * Поля, по которым разрешена сортировка.
     *
     * Белый список, а не проверка «есть такая колонка»: значение уходит в orderBy как есть.
     *
     * @var list<string>
     */
    private const SORTS = [
        'number', 'date', 'shipment_date', 'status', 'status_changed_at',
        'packages_count', 'items_count', 'total_quantity',
    ];

    /**
     * Потолок выгрузки. Экран режется страницами, файл — этим числом: журнал без
     * фильтров — это десятки тысяч ордеров, и вся таблица собирается в памяти
     * PhpSpreadsheet до отдачи.
     */
    private const EXPORT_LIMIT = 10000;

    private const EXPORT_CHUNK = 500;

    public function index(Request $request): Response
    {
        $query = $this->filteredQuery($request)
            ->with(['warehouse:id,name', 'company:id,name', 'organization:id,name']);

        [$sortBy, $sortOrder] = $this->sort($request);
        $perPage = min(max((int) $request->input('per_page', 30), 10), 100);

        $orders = $query
            ->orderBy($sortBy, $sortOrder)
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString()
            ->through($this->presentRow(...));

        return Inertia::render('Wms/Pages/GoodsIssues/Index', [
            'orders' => $orders,
            'filters' => $this->filterState($request),
            'stats' => $this->stats($request),
            'options' => [
                'statuses' => $this->statusOptions(),
                'priorities' => $this->labelOptions(GoodsIssue::PRIORITY_LABELS),
                'deliveryTypes' => $this->labelOptions(GoodsIssue::DELIVERY_TYPE_LABELS),
                'warehouses' => $this->warehouseOptions(),
                'responsibles' => $this->responsibleOptions(),
            ],
            'sort' => ['by' => $sortBy, 'order' => $sortOrder],
            'perPage' => $perPage,
            'staleHours' => GoodsIssue::STALE_HOURS,
        ]);
    }

    public function show(Request $request, GoodsIssue $goodsIssue): Response
    {
        $goodsIssue->load([
            'warehouse:id,name',
            'organization:id,name',
            'company:id,name',
            'user:id,name,erp_name,email',
            'items.product:id,name,sku',
            'items.order:id,number,erp_number',
            'packages',
            'statusHistories',
        ]);

        return Inertia::render('Wms/Pages/GoodsIssues/Show', [
            'order' => $this->presentCard($goodsIssue),
        ]);
    }

    /**
     * Выгрузка текущей выборки: лист «Ордера» + лист «Позиции».
     *
     * Именно выборки с фильтрами, а не всей таблицы: кладовщик выгружает то, что видит
     * на экране, — иначе файл не совпадает с журналом и им нельзя сверяться.
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $query = $this->filteredQuery($request)
            ->with(['warehouse:id,name', 'company:id,name'])
            ->orderBy('date', 'desc')
            ->limit(self::EXPORT_LIMIT);

        $orderRows = [];
        $itemRows = [];

        $query->chunk(self::EXPORT_CHUNK, function ($chunk) use (&$orderRows, &$itemRows) {
            $chunk->load('items');

            foreach ($chunk as $goodsIssue) {
                $orderRows[] = [
                    $goodsIssue->number,
                    $goodsIssue->date?->format('d.m.Y H:i'),
                    $goodsIssue->shipment_date?->format('d.m.Y H:i'),
                    $goodsIssue->status_label,
                    $goodsIssue->status_changed_at?->format('d.m.Y H:i'),
                    $goodsIssue->recipient_label,
                    $goodsIssue->warehouse?->name,
                    $goodsIssue->responsible,
                    $goodsIssue->priority_label,
                    $goodsIssue->delivery_type_label,
                    (int) $goodsIssue->items_count,
                    (float) $goodsIssue->total_quantity,
                    (int) $goodsIssue->packages_count,
                    $goodsIssue->comment,
                ];

                foreach ($goodsIssue->items as $item) {
                    $itemRows[] = [
                        $goodsIssue->number,
                        $item->line_number,
                        $item->product_label,
                        $item->product?->sku,
                        $item->order_number,
                        (float) $item->quantity,
                        $item->unit,
                        $item->cell,
                        $item->package_number,
                    ];
                }
            }
        });

        return $exporter->streamSheets('goods-issues-'.now()->format('Y-m-d'), [
            [
                'title' => 'Ордера',
                'headers' => [
                    'Номер', 'Дата', 'Дата отгрузки', 'Статус', 'Статус изменён',
                    'Получатель', 'Склад', 'Ответственный', 'Приоритет', 'Доставка',
                    'Позиций', 'Количество', 'Мест', 'Комментарий',
                ],
                'rows' => $orderRows,
            ],
            [
                'title' => 'Позиции',
                'headers' => [
                    'Ордер', 'N', 'Номенклатура', 'Артикул', 'Заказ',
                    'Количество', 'Ед. изм.', 'Ячейка', 'Место',
                ],
                'rows' => $itemRows,
            ],
        ]);
    }

    /**
     * Запрос с применёнными фильтрами.
     *
     * @param  bool  $withStatus  Ложь — статус не фильтруется. Нужно для плиток-счётчиков:
     *                            иначе при выбранном статусе все остальные показали бы ноль.
     * @return Builder<GoodsIssue>
     */
    private function filteredQuery(Request $request, bool $withStatus = true): Builder
    {
        $query = GoodsIssue::query();

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function (Builder $q) use ($search) {
                $like = "%{$search}%";
                $q->where('number', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', $like))
                    ->orWhereHas('items', fn (Builder $i) => $i
                        ->where('order_number', 'like', $like)
                        ->orWhere('product_name', 'like', $like)
                        ->orWhereHas('product', fn (Builder $p) => $p
                            ->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                        )
                    );
            });
        }

        if ($withStatus && $statuses = $this->arrayInput($request, 'statuses')) {
            $query->whereIn('status', $statuses);
        }

        if ($warehouseIds = $this->arrayInput($request, 'warehouse_ids')) {
            $query->whereIn('warehouse_id', $warehouseIds);
        }

        if ($responsibles = $this->arrayInput($request, 'responsibles')) {
            $query->whereIn('responsible', $responsibles);
        }

        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }

        if ($deliveryType = $request->input('delivery_type')) {
            $query->where('delivery_type', $deliveryType);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('date', '<=', $dateTo);
        }

        if ($shipFrom = $request->input('ship_from')) {
            $query->whereDate('shipment_date', '>=', $shipFrom);
        }

        if ($shipTo = $request->input('ship_to')) {
            $query->whereDate('shipment_date', '<=', $shipTo);
        }

        if ($request->boolean('stale')) {
            $query->stale();
        }

        if ($request->boolean('unresolved')) {
            $query->where('unresolved_items_count', '>', 0);
        }

        return $query;
    }

    /**
     * Счётчики по статусам и проблемным ордерам для текущей выборки.
     *
     * Считаются по запросу БЕЗ фильтра статуса — плитки остаются навигацией,
     * а не превращаются в «выбранный статус и пять нулей».
     *
     * @return array<string, mixed>
     */
    private function stats(Request $request): array
    {
        $base = $this->filteredQuery($request, withStatus: false);

        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'by_status' => collect(GoodsIssue::STATUSES)
                ->map(fn (string $status) => [
                    'value' => $status,
                    'label' => GoodsIssue::STATUS_LABELS[$status],
                    'color' => GoodsIssue::STATUS_COLORS[$status],
                    'count' => (int) ($byStatus[$status] ?? 0),
                ])
                ->all(),
            'total' => (int) $byStatus->sum(),
            'active' => (int) collect(GoodsIssue::ACTIVE_STATUSES)
                ->sum(fn (string $status) => (int) ($byStatus[$status] ?? 0)),
            'stale' => (clone $base)->stale()->count(),
            'unresolved' => (clone $base)->where('unresolved_items_count', '>', 0)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRow(GoodsIssue $goodsIssue): array
    {
        return [
            'id' => (int) $goodsIssue->getKey(),
            'number' => $goodsIssue->number,
            'date_label' => $goodsIssue->date?->format('d.m.Y H:i'),
            'shipment_date_label' => $goodsIssue->shipment_date?->format('d.m.Y H:i'),
            'status' => $goodsIssue->status,
            'status_label' => $goodsIssue->status_label,
            'status_color' => $goodsIssue->status_color,
            'status_changed_label' => $goodsIssue->status_changed_at?->diffForHumans(),
            'is_stale' => $goodsIssue->is_stale,
            'recipient' => $goodsIssue->recipient_label,
            'warehouse' => $goodsIssue->warehouse?->name,
            'responsible' => $goodsIssue->responsible,
            'priority' => $goodsIssue->priority,
            'priority_label' => $goodsIssue->priority_label,
            'delivery_type_label' => $goodsIssue->delivery_type_label,
            'items_count' => (int) $goodsIssue->items_count,
            'total_quantity' => (float) $goodsIssue->total_quantity,
            'packages_count' => (int) $goodsIssue->packages_count,
            'unresolved_items_count' => (int) $goodsIssue->unresolved_items_count,
            'url' => route('wms.goods-issues.show', $goodsIssue),
        ];
    }

    /**
     * Карточка ордера: позиции сгруппированы по заказам-распоряжениям.
     *
     * Группировка не косметика: кладовщик собирает ордер по распоряжениям, и плоский
     * список из шести строк по трём заказам заставляет его сортировать глазами.
     *
     * @return array<string, mixed>
     */
    private function presentCard(GoodsIssue $goodsIssue): array
    {
        $groups = $goodsIssue->items
            ->groupBy(fn (GoodsIssueItem $item) => $item->order_uuid ?? '')
            ->map(fn ($items, $orderUuid) => [
                'order_uuid' => $orderUuid ?: null,
                'order_number' => $items->first()->order_number,
                'order_date_label' => $items->first()->order_date?->format('d.m.Y H:i'),
                // Ссылки на заказ нет намеренно: карточка заказа живёт в /admin и /crm,
                // куда складские роли не пускают. Номера из 1С кладовщику достаточно.
                'is_known_order' => $items->first()->order_id !== null,
                'items' => $items->map(fn (GoodsIssueItem $item) => [
                    'id' => (int) $item->getKey(),
                    'line_number' => $item->line_number,
                    'product_name' => $item->product_label,
                    'product_sku' => $item->product?->sku,
                    'is_unresolved' => $item->product_id === null,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'cell' => $item->cell,
                    'package_number' => $item->package_number,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'id' => (int) $goodsIssue->getKey(),
            'number' => $goodsIssue->number,
            'uuid' => $goodsIssue->uuid,
            'date_label' => $goodsIssue->date?->format('d.m.Y H:i'),
            'shipment_date_label' => $goodsIssue->shipment_date?->format('d.m.Y H:i'),
            'status' => $goodsIssue->status,
            'status_label' => $goodsIssue->status_label,
            'status_color' => $goodsIssue->status_color,
            'status_changed_label' => $goodsIssue->status_changed_at?->format('d.m.Y H:i'),
            'is_stale' => $goodsIssue->is_stale,
            'operation' => $goodsIssue->operation,
            'recipient' => $goodsIssue->recipient_label,
            'tax_id' => $goodsIssue->tax_id,
            'warehouse' => $goodsIssue->warehouse?->name,
            'organization' => $goodsIssue->organization?->name,
            'responsible' => $goodsIssue->responsible,
            'priority_label' => $goodsIssue->priority_label,
            'comment' => $goodsIssue->comment,
            'delivery_type_label' => $goodsIssue->delivery_type_label,
            'delivery_address' => $goodsIssue->delivery_address,
            'delivery_order' => $goodsIssue->delivery_order,
            'items_count' => (int) $goodsIssue->items_count,
            'total_quantity' => (float) $goodsIssue->total_quantity,
            'packages_count' => (int) $goodsIssue->packages_count,
            'unresolved_items_count' => (int) $goodsIssue->unresolved_items_count,
            'erp_created_label' => $goodsIssue->erp_created_at?->format('d.m.Y H:i'),
            'erp_updated_label' => $goodsIssue->erp_updated_at?->format('d.m.Y H:i'),
            'groups' => $groups,
            'packages' => $goodsIssue->packages->map(fn ($package) => [
                'number' => (int) $package->number,
                'positions_count' => $package->positions_count,
                'weight' => $package->weight,
                'volume' => $package->volume,
            ])->all(),
            'history' => $goodsIssue->statusHistories->map(fn ($entry) => [
                'id' => (int) $entry->getKey(),
                'from_label' => $entry->from_status_label,
                'to_label' => $entry->to_status_label,
                'changed_label' => $entry->changed_at->format('d.m.Y H:i'),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterState(Request $request): array
    {
        return [
            'search' => (string) $request->input('search', ''),
            'statuses' => $this->arrayInput($request, 'statuses'),
            'warehouse_ids' => $this->arrayInput($request, 'warehouse_ids'),
            'responsibles' => $this->arrayInput($request, 'responsibles'),
            'priority' => (string) $request->input('priority', ''),
            'delivery_type' => (string) $request->input('delivery_type', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'ship_from' => (string) $request->input('ship_from', ''),
            'ship_to' => (string) $request->input('ship_to', ''),
            'stale' => $request->boolean('stale'),
            'unresolved' => $request->boolean('unresolved'),
        ];
    }

    /**
     * Значение фильтра-мультивыбора.
     *
     * Приходит и массивом (`statuses[]=a&statuses[]=b`), и строкой через запятую —
     * второе нужно, чтобы ссылка на журнал из плитки или письма оставалась читаемой.
     *
     * @return list<string>
     */
    private function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sort(Request $request): array
    {
        $sortBy = (string) $request->input('sort', 'date');
        $sortOrder = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortBy, self::SORTS, true)) {
            $sortBy = 'date';
        }

        return [$sortBy, $sortOrder];
    }

    /**
     * @return list<array{value: string, label: string, color: string}>
     */
    private function statusOptions(): array
    {
        return collect(GoodsIssue::STATUSES)
            ->map(fn (string $status) => [
                'value' => $status,
                'label' => GoodsIssue::STATUS_LABELS[$status],
                'color' => GoodsIssue::STATUS_COLORS[$status],
            ])
            ->all();
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function labelOptions(array $labels): array
    {
        return collect($labels)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * Склады, по которым реально есть ордера.
     *
     * Полный справочник здесь ни к чему: у склада их полтора десятка, а ордера
     * приходят с двух-трёх — остальные строки в фильтре только мешают.
     *
     * @return list<array{id: int, name: string}>
     */
    private function warehouseOptions(): array
    {
        return Warehouse::query()
            ->whereIn('id', GoodsIssue::query()->distinct()->pluck('warehouse_id')->filter())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Warehouse $warehouse) => [
                'id' => (int) $warehouse->id,
                'name' => $warehouse->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function responsibleOptions(): array
    {
        return GoodsIssue::query()
            ->whereNotNull('responsible')
            ->distinct()
            ->orderBy('responsible')
            ->pluck('responsible')
            ->map(fn (string $responsible) => ['id' => $responsible, 'name' => $responsible])
            ->all();
    }
}

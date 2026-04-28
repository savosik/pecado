<?php

namespace App\Http\Controllers\User;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Returns\ReturnService;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use App\Support\Search\FuzzyDocumentMatcher;
use App\Support\Search\MatchSourceResolver;
use App\Support\Search\QueryRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returnService) {}

    /**
     * Список возвратов текущего пользователя.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        [$query, $context] = $this->buildIndexQuery($request, $user);
        $search = $context['search'];
        $perPage = $context['per_page'];

        // Заглушка прежней структуры для дальнейшего pagination + transform.
        $returns = $query->paginate($perPage)->withQueryString();
        $returns->getCollection()->transform(function ($return) use ($search) {
            $match = MatchSourceResolver::resolve(
                $return,
                $search,
                directFields: [
                    ['field' => 'erp_number', 'source' => 'number'],
                    ['field' => 'uuid', 'source' => 'number'],
                ],
                itemFields: [
                    ['relation' => 'items', 'field' => 'product_name_snapshot', 'source' => 'composition'],
                    ['relation' => 'items', 'field' => 'brand_name_snapshot', 'source' => 'composition'],
                    ['relation' => 'items', 'field' => 'reason_comment', 'source' => 'comment'],
                ],
            );

            return [
                'id' => $return->id,
                'number' => $return->erp_number ?? ('#'.$return->id),
                'uuid' => $return->uuid,
                'status' => $return->status?->value,
                'status_label' => $this->getStatusLabel($return->status),
                'total_amount' => $return->total_amount,
                'created_at' => $return->created_at?->format('d.m.Y H:i'),
                'items_count' => $return->items->count(),
                'primary_reason' => $return->items->first()?->reason?->value,
                'primary_reason_label' => $this->getReasonLabel($return->items->first()?->reason),
                'match_source' => $match['source'],
                'match_snippet' => $match['snippet'],
            ];
        });

        return Inertia::render('User/Cabinet/Returns/Index', [
            'returns' => $returns,
            'filters' => [
                'search' => $search,
                'status' => $context['selected_statuses'],
                'reason' => $context['reasons'],
                'date_from' => $context['date_from'],
                'date_to' => $context['date_to'],
                'amount_from' => $context['amount_from'],
                'amount_to' => $context['amount_to'],
                'sort_by' => $context['sort_by'],
                'sort_order' => $context['sort_order'],
                'per_page' => $perPage,
            ],
            'statuses' => collect(ReturnStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
            ]),
            'reasons' => collect(ReturnReason::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getReasonLabel($case),
            ]),
            'exportEnabled' => (bool) config('search-cabinet.export'),
        ]);
    }

    /**
     * Экспорт текущей выдачи в CSV/XLSX. PR 5.2.
     * GET /cabinet/returns/export?format=csv|xlsx
     */
    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        abort_unless((bool) config('search-cabinet.export'), 404);

        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        [$query] = $this->buildIndexQuery($request, $request->user());

        $headers = ['Номер', 'Статус', 'Причина', 'Позиций', 'Сумма', 'Дата'];

        $rows = (function () use ($query) {
            foreach ($query->cursor() as $return) {
                yield [
                    $return->erp_number ?? ('#'.$return->id),
                    $this->getStatusLabel($return->status),
                    $this->getReasonLabel($return->items->first()?->reason),
                    $return->items->count(),
                    round((float) $return->total_amount, 2),
                    $return->created_at?->format('d.m.Y H:i'),
                ];
            }
        })();

        $filename = 'returns-'.now()->format('Y-m-d-His');

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $rows)
            : $xlsx->stream($filename, $headers, $rows, 'Возвраты');
    }

    private function buildIndexQuery(Request $request, User $user): array
    {
        $search = trim((string) $request->input('search', ''));

        $query = ProductReturn::query()
            ->where('user_id', $user->id)
            ->with(['items']);

        if ($search !== '') {
            $normalized = preg_replace('/[\s\-]+/u', '', $search);
            $queryType = QueryRouter::classify($search);

            $fuzzyReturnIds = FuzzyDocumentMatcher::isApplicable($search, $queryType)
                ? FuzzyDocumentMatcher::findDocumentIds(
                    $search,
                    ReturnItem::class,
                    'return_id',
                    'return',
                    $user->id,
                )
                : [];

            $query->where(function ($q) use ($search, $normalized, $queryType, $fuzzyReturnIds) {
                // Базовое: UUID / ERP-номер / числовой ID.
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }

                // Нормализованная форма ERP-номера (C-2.1): дефис/пробелы съедаются с обеих сторон.
                if ($normalized !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                }

                // Поиск по номеру исходной реализации (C-2.2).
                $q->orWhereHas('items.shipmentItem.shipment', function ($s) use ($search, $normalized) {
                    $s->where('number', 'like', "%{$search}%")
                        ->orWhere('erp_number', 'like', "%{$search}%")
                        ->orWhere('uuid', 'like', "%{$search}%");
                    if ($normalized !== '') {
                        $s->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                        $s->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                    }
                });

                // Состав возврата: name/sku/code товара (C-2.3).
                $q->orWhereHas('items.shipmentItem.product', function ($p) use ($search) {
                    $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });

                // Бренд в составе (C-2.3).
                $q->orWhereHas('items.shipmentItem.product.brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));

                // Штрихкод (C-2.3, точное совпадение).
                if ($queryType === QueryRouter::TYPE_BARCODE) {
                    $q->orWhereHas('items.shipmentItem.product.barcodes', fn ($b) => $b->where('barcode', $search));
                }

                // Текст комментария причины (C-2.4).
                $q->orWhereHas('items', fn ($i) => $i->where('reason_comment', 'like', "%{$search}%"));

                // Fuzzy через Meilisearch (PR 4.2, флаг CABINET_SEARCH_FUZZY_DOCUMENTS).
                if (! empty($fuzzyReturnIds)) {
                    $q->orWhereIn('id', $fuzzyReturnIds);
                }
            });
        }

        // Статус — поддерживаем скаляр (старое поведение) и массив (multi-select).
        $statusInput = $request->input('status');
        if (is_array($statusInput)) {
            $statuses = array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''));
            if (count($statuses) > 0) {
                $query->whereIn('status', $statuses);
            }
        } elseif ($statusInput) {
            $query->where('status', $statusInput);
        }

        // Причина — multi-select (C-2.5) с обратной совместимостью со скалярным значением.
        $reasonInput = $request->input('reason');
        $reasons = is_array($reasonInput)
            ? array_values(array_filter($reasonInput, fn ($v) => $v !== null && $v !== ''))
            : ($reasonInput ? [$reasonInput] : []);
        if (count($reasons) > 0) {
            $query->whereHas('items', fn ($q) => $q->whereIn('reason', $reasons));
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($amountFrom = $request->input('amount_from')) {
            $query->where('total_amount', '>=', $amountFrom);
        }
        if ($amountTo = $request->input('amount_to')) {
            $query->where('total_amount', '<=', $amountTo);
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSortFields = ['id', 'total_amount', 'status', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $selectedStatuses = is_array($statusInput)
            ? array_values(array_filter($statusInput, fn ($v) => $v !== null && $v !== ''))
            : ($statusInput ? [(string) $statusInput] : []);

        return [$query, [
            'search' => $search,
            'selected_statuses' => $selectedStatuses,
            'reasons' => $reasons,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'per_page' => $perPage,
        ]];
    }

    /**
     * Форма создания возврата.
     */
    public function create(): InertiaResponse
    {
        return Inertia::render('User/Cabinet/Returns/Create', [
            'reasons' => collect(ReturnReason::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getReasonLabel($case),
            ]),
        ]);
    }

    /**
     * Сохранение нового возврата.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.shipment_item_id' => 'required|integer|exists:shipment_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|in:'.implode(',', array_column(ReturnReason::cases(), 'value')),
            'items.*.reason_comment' => 'nullable|string',
        ], [
            'items.required' => 'Добавьте хотя бы одну позицию возврата.',
            'items.min' => 'Добавьте хотя бы одну позицию возврата.',
            'items.*.shipment_item_id.required' => 'Выберите позицию реализации.',
            'items.*.shipment_item_id.exists' => 'Выбранная позиция реализации не найдена.',
            'items.*.quantity.required' => 'Укажите количество.',
            'items.*.quantity.min' => 'Количество должно быть не менее 1.',
            'items.*.reason.required' => 'Укажите причину возврата.',
            'items.*.reason.in' => 'Недопустимая причина возврата.',
        ]);

        try {
            $return = $this->returnService->createForUser($user, $validated);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage() ?: 'Ошибка при создании возврата.');
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Ошибка при создании возврата: '.$e->getMessage());
        }

        return redirect()
            ->route('cabinet.returns.show', $return)
            ->with('success', 'Заявка на возврат успешно создана.');
    }

    /**
     * Просмотр возврата.
     */
    public function show(Request $request, ProductReturn $return): InertiaResponse
    {
        $user = $request->user();
        abort_unless($return->user_id === $user->id, 403);

        $return->load(['items.product', 'items.shipmentItem.shipment']);

        return Inertia::render('User/Cabinet/Returns/Show', [
            'return' => [
                'id' => $return->id,
                'number' => $return->erp_number ?? ('#'.$return->id),
                'uuid' => $return->uuid,
                'status' => $return->status?->value,
                'status_label' => $this->getStatusLabel($return->status),
                'total_amount' => $return->total_amount,
                'comment' => $return->comment,
                'created_at' => $return->created_at?->format('d.m.Y H:i'),
                'updated_at' => $return->updated_at?->format('d.m.Y H:i'),
                'items' => $return->items->map(function ($item) {
                    $shipment = $item->shipmentItem?->shipment;

                    return [
                        'id' => $item->id,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'subtotal' => $item->subtotal,
                        'reason' => $item->reason?->value,
                        'reason_label' => $this->getReasonLabel($item->reason),
                        'reason_comment' => $item->reason_comment,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                            'slug' => $item->product->slug,
                            'image_url' => $item->product->getFirstMediaUrl('main'),
                        ] : null,
                        'shipment' => $shipment ? [
                            'id' => $shipment->id,
                            'uuid' => $shipment->uuid,
                            'number' => $shipment->number,
                            'date' => $shipment->date?->format('d.m.Y'),
                            'currency_code' => $shipment->currency_code,
                        ] : null,
                    ];
                }),
            ],
            'statuses' => collect(ReturnStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
            ]),
        ]);
    }

    /**
     * Автокомплит реализаций текущего пользователя.
     *
     * Расширенный поиск (см. docs/cabinet-search-scenarios.md §3, C-3.1 … C-3.4):
     * - C-3.1: номер/erp_number, в т.ч. без дефиса (нормализация);
     * - C-3.2: товар в составе по name/sku/code + бренду + штрихкоду (точный матч для 8/12/13/14 цифр);
     * - C-3.4: open_returns_count — кол-во возвратов по реализации в незакрытых статусах.
     */
    public function searchShipments(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->input('query'));

        $query = Shipment::query()
            ->select('shipments.*')
            ->where('user_id', $user->id)
            ->with(['items.product.brand'])
            ->withCount('items')
            ->selectSub(function ($sub) {
                $sub->from('return_items')
                    ->join('returns', 'returns.id', '=', 'return_items.return_id')
                    ->whereColumn('return_items.shipment_id', 'shipments.id')
                    ->whereNotIn('returns.status', [
                        ReturnStatus::CLOSED->value,
                        ReturnStatus::CANCELLED->value,
                    ])
                    ->selectRaw('COUNT(DISTINCT return_items.return_id)');
            }, 'open_returns_count')
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($q !== '') {
            $normalized = preg_replace('/[\s\-]+/u', '', $q);
            $queryType = QueryRouter::classify($q);

            $query->where(function ($sub) use ($q, $normalized, $queryType) {
                $sub->where('number', 'like', "%{$q}%")
                    ->orWhere('erp_number', 'like', "%{$q}%");

                $sub->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                $sub->orWhereRaw("REPLACE(REPLACE(COALESCE(erp_number, ''), '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);

                $sub->orWhereHas('items.product', function ($p) use ($q, $queryType) {
                    $p->where(function ($pp) use ($q, $queryType) {
                        $pp->where('name', 'like', "%{$q}%")
                            ->orWhere('sku', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%")
                            ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$q}%"));

                        if ($queryType === QueryRouter::TYPE_BARCODE) {
                            $pp->orWhereHas('barcodes', fn ($bc) => $bc->where('barcode', $q));
                        }
                    });
                });
            });
        }

        $shipments = $query->limit(20)->get()->map(function (Shipment $s) use ($q) {
            $matchSource = 'number';
            $matchProduct = null;

            if ($q !== '') {
                $normalized = preg_replace('/[\s\-]+/u', '', $q);
                $shipmentNumberNormalized = preg_replace('/[\s\-]+/u', '', (string) $s->number);
                $erpNumberNormalized = preg_replace('/[\s\-]+/u', '', (string) ($s->erp_number ?? ''));
                $needle = mb_strtolower($q);
                $needleNormalized = mb_strtolower((string) $normalized);

                $hitsNumber = str_contains(mb_strtolower((string) $s->number), $needle)
                    || str_contains(mb_strtolower((string) $shipmentNumberNormalized), $needleNormalized);
                $hitsErp = $s->erp_number !== null && (
                    str_contains(mb_strtolower((string) $s->erp_number), $needle)
                    || str_contains(mb_strtolower((string) $erpNumberNormalized), $needleNormalized)
                );

                if (! $hitsNumber && ! $hitsErp) {
                    $matchSource = 'composition';
                    $matchProduct = $this->pickMatchedProduct($s, $q);
                } elseif ($hitsErp && ! $hitsNumber) {
                    $matchSource = 'erp_number';
                }
            }

            return [
                'id' => $s->id,
                'uuid' => $s->uuid,
                'number' => $s->number,
                'erp_number' => $s->erp_number,
                'date' => $s->date?->format('d.m.Y'),
                'total_amount' => $s->total_amount,
                'currency_code' => $s->currency_code,
                'items_count' => $s->items_count,
                'open_returns_count' => (int) ($s->open_returns_count ?? 0),
                'match_source' => $matchSource,
                'match_product' => $matchProduct,
                'label' => 'Реализация '.$s->number.($s->date ? ' от '.$s->date->format('d.m.Y') : ''),
            ];
        });

        return response()->json($shipments);
    }

    /**
     * Найти первый товар в составе реализации, который дал совпадение с запросом.
     */
    private function pickMatchedProduct(Shipment $shipment, string $q): ?array
    {
        $needle = mb_strtolower($q);

        foreach ($shipment->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $hit = str_contains(mb_strtolower((string) $product->name), $needle)
                || str_contains(mb_strtolower((string) $product->sku), $needle)
                || str_contains(mb_strtolower((string) $product->code), $needle)
                || ($product->brand && str_contains(mb_strtolower((string) $product->brand->name), $needle));

            if ($hit) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ];
            }
        }

        return null;
    }

    /**
     * Позиции выбранной реализации с доступным к возврату количеством.
     */
    public function getShipmentItems(Request $request): JsonResponse
    {
        $user = $request->user();
        $shipmentId = (int) $request->input('shipment_id');

        $shipment = Shipment::where('id', $shipmentId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $items = ShipmentItem::with('product')
            ->where('shipment_id', $shipment->id)
            ->get()
            ->map(function (ShipmentItem $si) use ($shipment) {
                $already = (int) ReturnItem::where('shipment_item_id', $si->id)->sum('quantity');
                $available = max(0, (int) $si->quantity - $already);

                return [
                    'shipment_item_id' => $si->id,
                    'product' => $si->product ? [
                        'id' => $si->product->id,
                        'name' => $si->product->name,
                        'sku' => $si->product->sku,
                        'image_url' => $si->product->getFirstMediaUrl('main'),
                    ] : null,
                    'price' => (float) $si->price,
                    'currency_code' => $shipment->currency_code,
                    'shipped_quantity' => (int) $si->quantity,
                    'already_returned' => $already,
                    'available_quantity' => $available,
                ];
            });

        return response()->json([
            'shipment' => [
                'id' => $shipment->id,
                'uuid' => $shipment->uuid,
                'number' => $shipment->number,
                'date' => $shipment->date?->format('d.m.Y'),
                'currency_code' => $shipment->currency_code,
            ],
            'items' => $items,
        ]);
    }

    protected function getStatusLabel(?ReturnStatus $status): string
    {
        return $status?->label() ?? 'Неизвестно';
    }

    protected function getReasonLabel(?ReturnReason $reason): string
    {
        return match ($reason) {
            ReturnReason::DEFECTIVE => 'Бракованный товар',
            ReturnReason::WRONG_ITEM => 'Неправильный товар',
            ReturnReason::CHANGED_MIND => 'Передумал',
            ReturnReason::DAMAGED_IN_TRANSIT => 'Повреждён при доставке',
            ReturnReason::WRONG_SIZE => 'Неправильный размер',
            ReturnReason::OTHER => 'Другое',
            default => 'Не указано',
        };
    }
}

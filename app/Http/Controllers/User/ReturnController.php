<?php

namespace App\Http\Controllers\User;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\Returns\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returnService) {}

    /**
     * Список возвратов текущего пользователя.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $query = ProductReturn::query()
            ->where('user_id', $user->id)
            ->with(['items']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($reason = $request->input('reason')) {
            $query->whereHas('items', function ($q) use ($reason) {
                $q->where('reason', $reason);
            });
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

        $returns = $query->paginate($perPage)->withQueryString();

        $returns->getCollection()->transform(function ($return) {
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
            ];
        });

        return Inertia::render('User/Cabinet/Returns/Index', [
            'returns' => $returns,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'reason' => $reason,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'amount_from' => $amountFrom,
                'amount_to' => $amountTo,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
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
        ]);
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
     * Автокомплит реализаций текущего пользователя по номеру/дате.
     */
    public function searchShipments(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->input('query'));

        $query = Shipment::query()
            ->where('user_id', $user->id)
            ->withCount('items')
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('number', 'like', "%{$q}%")
                    ->orWhere('erp_number', 'like', "%{$q}%");
            });
        }

        $shipments = $query->limit(20)->get()->map(fn (Shipment $s) => [
            'id' => $s->id,
            'uuid' => $s->uuid,
            'number' => $s->number,
            'date' => $s->date?->format('d.m.Y'),
            'total_amount' => $s->total_amount,
            'currency_code' => $s->currency_code,
            'items_count' => $s->items_count,
            'label' => 'Реализация '.$s->number.($s->date ? ' от '.$s->date->format('d.m.Y') : ''),
        ]);

        return response()->json($shipments);
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
        return match ($status) {
            ReturnStatus::PENDING => 'Ожидает',
            ReturnStatus::APPROVED => 'Одобрен',
            ReturnStatus::REJECTED => 'Отклонён',
            ReturnStatus::COMPLETED => 'Завершён',
            default => 'Неизвестно',
        };
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

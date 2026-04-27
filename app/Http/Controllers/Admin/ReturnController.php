<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Returns\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReturnController extends AdminController
{
    use RedirectsAfterSave;

    public function __construct(private readonly ReturnService $returnService) {}

    /**
     * Display a listing of returns.
     */
    public function index(Request $request): Response
    {
        $onlyTrashed = $request->boolean('trashed');

        $query = $onlyTrashed
            ? ProductReturn::onlyTrashed()->with(['user', 'items'])
            : ProductReturn::query()->with(['user', 'items']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%")
                    ->orWhere('id', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
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
        $allowedSortFields = ['id', 'uuid', 'total_amount', 'status', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $returns = $query->paginate($perPage)->withQueryString();

        $returns->through(function ($return) {
            return [
                'id' => $return->id,
                'uuid' => $return->uuid,
                'number' => $return->erp_number,
                'status' => $return->status?->value,
                'status_label' => $this->getStatusLabel($return->status),
                'total_amount' => $return->total_amount,
                'created_at' => $return->created_at?->format('d.m.Y H:i'),
                'deleted_at' => $return->deleted_at?->format('d.m.Y H:i'),
                'user' => $return->user ? [
                    'id' => $return->user->id,
                    'name' => $return->user->name,
                    'email' => $return->user->email,
                ] : null,
                'items_count' => $return->items->count(),
                'primary_reason' => $return->items->first()?->reason?->value,
                'primary_reason_label' => $this->getReasonLabel($return->items->first()?->reason),
            ];
        });

        return Inertia::render('Admin/Pages/Returns/Index', [
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
                'trashed' => $onlyTrashed,
            ],
            'trashedCount' => ProductReturn::onlyTrashed()->count(),
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
     * Show the form for creating a new return.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Returns/Create', [
            'users' => User::select('id', 'name', 'email')->orderBy('name')->get(),
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
     * Store a newly created return in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'nullable|string|in:'.implode(',', array_column(ReturnStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'admin_comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.shipment_item_id' => 'required|integer|exists:shipment_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|in:'.implode(',', array_column(ReturnReason::cases(), 'value')),
            'items.*.reason_comment' => 'nullable|string',
        ]);

        $user = User::findOrFail($validated['user_id']);

        try {
            $return = $this->returnService->createForAnyUser($user, [
                'comment' => $validated['comment'] ?? null,
                'items' => $validated['items'],
            ]);

            if (! empty($validated['status']) || ! empty($validated['admin_comment'])) {
                $return->update(array_filter([
                    'status' => $validated['status'] ?? null,
                    'admin_comment' => $validated['admin_comment'] ?? null,
                ]));
            }
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

        return $this->redirectAfterSave($request, 'admin.returns.index', 'admin.returns.edit', $return, 'Возврат успешно создан');
    }

    /**
     * Show the form for editing the specified return.
     */
    public function edit(ProductReturn $return): Response
    {
        $return->load(['user', 'items.product', 'items.shipmentItem.shipment']);

        return Inertia::render('Admin/Pages/Returns/Edit', [
            'return' => [
                'id' => $return->id,
                'uuid' => $return->uuid,
                'number' => $return->erp_number,
                'user_id' => $return->user_id,
                'status' => $return->status?->value,
                'comment' => $return->comment,
                'admin_comment' => $return->admin_comment,
                'total_amount' => $return->total_amount,
                'items' => $return->items->map(function ($item) {
                    $shipment = $item->shipmentItem?->shipment;

                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'shipment_item_id' => $item->shipment_item_id,
                        'shipment_id' => $item->shipment_id,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'subtotal' => $item->subtotal,
                        'reason' => $item->reason?->value,
                        'reason_comment' => $item->reason_comment,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
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
            'users' => User::select('id', 'name', 'email')->orderBy('name')->get(),
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
     * Update the specified return in storage.
     *
     * Позволяет менять шапку возврата и количество/причину существующих позиций;
     * привязка shipment_item_id/shipment_id у существующих строк не перепривязывается,
     * чтобы не ломать snapshot цены.
     */
    public function update(Request $request, ProductReturn $return): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'required|string|in:'.implode(',', array_column(ReturnStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'admin_comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:return_items,id',
            'items.*.shipment_item_id' => 'required|integer|exists:shipment_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|in:'.implode(',', array_column(ReturnReason::cases(), 'value')),
            'items.*.reason_comment' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $return->update([
                'user_id' => $validated['user_id'],
                'status' => $validated['status'],
                'comment' => $validated['comment'] ?? null,
                'admin_comment' => $validated['admin_comment'] ?? null,
            ]);

            $existingItemIds = [];
            $total = 0;

            foreach ($validated['items'] as $item) {
                /** @var ShipmentItem $si */
                $si = ShipmentItem::with('shipment')->findOrFail($item['shipment_item_id']);
                $price = (float) $si->price;
                $quantity = (int) $item['quantity'];
                $subtotal = round($price * $quantity, 2);
                $total += $subtotal;

                $payload = [
                    'shipment_item_id' => $si->id,
                    'shipment_id' => $si->shipment_id,
                    'product_id' => $si->product_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'subtotal' => $subtotal,
                    'reason' => $item['reason'],
                    'reason_comment' => $item['reason_comment'] ?? null,
                ];

                if (! empty($item['id'])) {
                    $returnItem = $return->items()->find($item['id']);
                    if ($returnItem) {
                        $returnItem->update($payload);
                        $existingItemIds[] = $returnItem->id;
                    }
                } else {
                    $new = $return->items()->create($payload);
                    $existingItemIds[] = $new->id;
                }
            }

            $return->items()->whereNotIn('id', $existingItemIds)->delete();
            $return->update(['total_amount' => $total]);

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.returns.index', 'admin.returns.edit', $return, 'Возврат успешно обновлён');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Ошибка при обновлении возврата: '.$e->getMessage());
        }
    }

    /**
     * Display the specified return.
     */
    public function show(ProductReturn $return): Response
    {
        $return->load(['user', 'items.product', 'items.shipmentItem.shipment']);

        return Inertia::render('Admin/Pages/Returns/Show', [
            'return' => [
                'id' => $return->id,
                'uuid' => $return->uuid,
                'number' => $return->erp_number,
                'status' => $return->status?->value,
                'status_label' => $this->getStatusLabel($return->status),
                'total_amount' => $return->total_amount,
                'comment' => $return->comment,
                'admin_comment' => $return->admin_comment,
                'created_at' => $return->created_at?->format('d.m.Y H:i'),
                'updated_at' => $return->updated_at?->format('d.m.Y H:i'),
                'user' => $return->user ? [
                    'id' => $return->user->id,
                    'name' => $return->user->name,
                    'email' => $return->user->email,
                    'phone' => $return->user->phone,
                ] : null,
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
     * Update the status of the specified return.
     */
    public function updateStatus(Request $request, ProductReturn $return): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', array_column(ReturnStatus::cases(), 'value')),
        ]);

        $return->update(['status' => $validated['status']]);

        return redirect()->back()->with('success', 'Статус возврата успешно обновлён');
    }

    /**
     * Update the admin comment of the specified return.
     */
    public function updateAdminComment(Request $request, ProductReturn $return): RedirectResponse
    {
        $validated = $request->validate([
            'admin_comment' => 'nullable|string',
        ]);

        $return->update(['admin_comment' => $validated['admin_comment']]);

        return redirect()->back()->with('success', 'Комментарий администратора обновлён');
    }

    /**
     * Bulk update status for selected returns.
     */
    public function bulkUpdateStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'return_ids' => 'required|array|min:1',
            'return_ids.*' => 'exists:returns,id',
            'status' => 'required|string|in:'.implode(',', array_column(ReturnStatus::cases(), 'value')),
        ]);

        $count = ProductReturn::whereIn('id', $validated['return_ids'])->update([
            'status' => $validated['status'],
        ]);

        return redirect()->back()->with('success', "Статус обновлён для {$count} возвратов");
    }

    /**
     * Remove the specified return from storage (soft delete).
     */
    public function destroy(ProductReturn $return): RedirectResponse
    {
        $return->delete();

        return redirect()->route('admin.returns.index')->with('success', 'Возврат успешно удалён');
    }

    /**
     * Permanently delete a soft-deleted return.
     */
    public function forceDestroy(int $id): RedirectResponse
    {
        $return = ProductReturn::onlyTrashed()->findOrFail($id);
        $return->forceDelete();

        return redirect()->route('admin.returns.index', ['trashed' => 1])->with('success', 'Возврат окончательно удалён');
    }

    /**
     * Автокомплит реализаций (с опциональной фильтрацией по пользователю).
     */
    public function searchShipments(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('query'));
        $userId = $request->input('user_id');

        $query = Shipment::query()
            ->with('user:id,name,email')
            ->withCount('items')
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($userId) {
            $query->where('user_id', $userId);
        }

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
            'user' => $s->user ? [
                'id' => $s->user->id,
                'name' => $s->user->name,
                'email' => $s->user->email,
            ] : null,
            'label' => 'Реализация '.$s->number.($s->date ? ' от '.$s->date->format('d.m.Y') : ''),
        ]);

        return response()->json($shipments);
    }

    /**
     * Позиции реализации с доступным к возврату количеством.
     */
    public function getShipmentItems(Request $request): JsonResponse
    {
        $shipmentId = (int) $request->input('shipment_id');

        $shipment = Shipment::findOrFail($shipmentId);

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
                'user_id' => $shipment->user_id,
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

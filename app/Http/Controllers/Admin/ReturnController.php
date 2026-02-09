<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Models\ProductReturn;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class ReturnController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of returns.
     */
    public function index(Request $request): Response
    {
        $query = ProductReturn::query()
            ->with(['user', 'items']);

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                  ->orWhere('id', $search)
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Фильтрация по статусу
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Фильтрация по причине возврата
        if ($reason = $request->input('reason')) {
            $query->whereHas('items', function ($q) use ($reason) {
                $q->where('reason', $reason);
            });
        }

        // Фильтрация по дате создания
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Фильтрация по сумме
        if ($amountFrom = $request->input('amount_from')) {
            $query->where('total_amount', '>=', $amountFrom);
        }
        if ($amountTo = $request->input('amount_to')) {
            $query->where('total_amount', '<=', $amountTo);
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'uuid', 'total_amount', 'status', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $returns = $query->paginate($perPage)->withQueryString();

        // Трансформация данных
        $returns->through(function ($return) {
            return [
                'id' => $return->id,
                'uuid' => $return->uuid,
                'status' => $return->status?->value,
                'status_label' => $this->getStatusLabel($return->status),
                'total_amount' => $return->total_amount,
                'created_at' => $return->created_at?->format('d.m.Y H:i'),
                'user' => $return->user ? [
                    'id' => $return->user->id,
                    'name' => $return->user->full_name,
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
            'status' => 'required|string|in:' . implode(',', array_column(ReturnStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'admin_comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.order_id' => 'nullable|exists:orders,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.reason' => 'required|string|in:' . implode(',', array_column(ReturnReason::cases(), 'value')),
            'items.*.reason_comment' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Подсчёт total_amount
            $totalAmount = collect($validated['items'])->sum(function ($item) {
                return $item['price'] * $item['quantity'];
            });

            // Создание возврата
            $return = ProductReturn::create([
                'user_id' => $validated['user_id'],
                'status' => $validated['status'],
                'comment' => $validated['comment'] ?? null,
                'admin_comment' => $validated['admin_comment'] ?? null,
                'total_amount' => $totalAmount,
            ]);

            // Создание позиций возврата
            foreach ($validated['items'] as $item) {
                $return->items()->create([
                    'product_id' => $item['product_id'],
                    'order_id' => $item['order_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $item['price'] * $item['quantity'],
                    'reason' => $item['reason'],
                    'reason_comment' => $item['reason_comment'] ?? null,
                ]);
            }

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.returns.index', 'admin.returns.edit', $return, 'Возврат успешно создан');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Ошибка при создании возврата: ' . $e->getMessage());
        }
    }

    /**
     * Show the form for editing the specified return.
     */
    public function edit(ProductReturn $return): Response
    {
        $return->load(['user', 'items.product', 'items.order']);

        return Inertia::render('Admin/Pages/Returns/Edit', [
            'return' => [
                'id' => $return->id,
                'uuid' => $return->uuid,
                'user_id' => $return->user_id,
                'status' => $return->status?->value,
                'comment' => $return->comment,
                'admin_comment' => $return->admin_comment,
                'total_amount' => $return->total_amount,
                'items' => $return->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'order_id' => $item->order_id,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'subtotal' => $item->subtotal,
                        'reason' => $item->reason?->value,
                        'reason_comment' => $item->reason_comment,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
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
     */
    public function update(Request $request, ProductReturn $return): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'required|string|in:' . implode(',', array_column(ReturnStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'admin_comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:return_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.order_id' => 'nullable|exists:orders,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.reason' => 'required|string|in:' . implode(',', array_column(ReturnReason::cases(), 'value')),
            'items.*.reason_comment' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Подсчёт total_amount
            $totalAmount = collect($validated['items'])->sum(function ($item) {
                return $item['price'] * $item['quantity'];
            });

            // Обновление возврата
            $return->update([
                'user_id' => $validated['user_id'],
                'status' => $validated['status'],
                'comment' => $validated['comment'] ?? null,
                'admin_comment' => $validated['admin_comment'] ?? null,
                'total_amount' => $totalAmount,
            ]);

            // Синхронизация позиций возврата
            $existingItemIds = [];
            foreach ($validated['items'] as $item) {
                if (!empty($item['id'])) {
                    // Обновление существующей позиции
                    $returnItem = $return->items()->find($item['id']);
                    if ($returnItem) {
                        $returnItem->update([
                            'product_id' => $item['product_id'],
                            'order_id' => $item['order_id'] ?? null,
                            'quantity' => $item['quantity'],
                            'price' => $item['price'],
                            'subtotal' => $item['price'] * $item['quantity'],
                            'reason' => $item['reason'],
                            'reason_comment' => $item['reason_comment'] ?? null,
                        ]);
                        $existingItemIds[] = $item['id'];
                    }
                } else {
                    // Создание новой позиции
                    $newItem = $return->items()->create([
                        'product_id' => $item['product_id'],
                        'order_id' => $item['order_id'] ?? null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'subtotal' => $item['price'] * $item['quantity'],
                        'reason' => $item['reason'],
                        'reason_comment' => $item['reason_comment'] ?? null,
                    ]);
                    $existingItemIds[] = $newItem->id;
                }
            }

            // Удаление позиций, которых больше нет
            $return->items()->whereNotIn('id', $existingItemIds)->delete();

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.returns.index', 'admin.returns.edit', $return, 'Возврат успешно обновлён');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Ошибка при обновлении возврата: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified return.
     */
    public function show(ProductReturn $return): Response
    {
        $return->load(['user', 'items.product', 'items.order']);

        return Inertia::render('Admin/Pages/Returns/Show', [
            'return' => [
                'id' => $return->id,
                'uuid' => $return->uuid,
                'status' => $return->status?->value,
                'status_label' => $this->getStatusLabel($return->status),
                'total_amount' => $return->total_amount,
                'comment' => $return->comment,
                'admin_comment' => $return->admin_comment,
                'created_at' => $return->created_at?->format('d.m.Y H:i'),
                'updated_at' => $return->updated_at?->format('d.m.Y H:i'),
                'user' => $return->user ? [
                    'id' => $return->user->id,
                    'name' => $return->user->full_name,
                    'email' => $return->user->email,
                    'phone' => $return->user->phone,
                ] : null,
                'items' => $return->items->map(function ($item) {
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
                        'order' => $item->order ? [
                            'id' => $item->order->id,
                            'uuid' => $item->order->uuid,
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
            'status' => 'required|string|in:' . implode(',', array_column(ReturnStatus::cases(), 'value')),
        ]);

        $return->update([
            'status' => $validated['status'],
        ]);

        return redirect()
            ->back()
            ->with('success', 'Статус возврата успешно обновлён');
    }

    /**
     * Update the admin comment of the specified return.
     */
    public function updateAdminComment(Request $request, ProductReturn $return): RedirectResponse
    {
        $validated = $request->validate([
            'admin_comment' => 'nullable|string',
        ]);

        $return->update([
            'admin_comment' => $validated['admin_comment'],
        ]);

        return redirect()
            ->back()
            ->with('success', 'Комментарий администратора обновлён');
    }

    /**
     * Bulk update status for selected returns.
     */
    public function bulkUpdateStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'return_ids' => 'required|array|min:1',
            'return_ids.*' => 'exists:returns,id',
            'status' => 'required|string|in:' . implode(',', array_column(ReturnStatus::cases(), 'value')),
        ]);

        $count = ProductReturn::whereIn('id', $validated['return_ids'])->update([
            'status' => $validated['status'],
        ]);

        return redirect()
            ->back()
            ->with('success', "Статус обновлён для {$count} возвратов");
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
     * Search products for return items (with optional user filtering).
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $query = $request->input('query');
        $userId = $request->input('user_id');

        if (!$query) {
            return response()->json([]);
        }

        // Поиск через Meilisearch
        $products = Product::search($query)
            ->query(fn ($q) => $q->with(['media', 'barcodes', 'brand'])->limit(20))
            ->get();

        // Если указан user_id, фильтруем только товары из заказов этого пользователя
        if ($userId) {
            $orderedProductIds = Order::where('user_id', $userId)
                ->with('items')
                ->get()
                ->flatMap(fn ($order) => $order->items->pluck('product_id'))
                ->unique()
                ->toArray();

            $products = $products->filter(fn ($product) => in_array($product->id, $orderedProductIds));
        }

        return response()->json($products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'price' => $product->base_price,
                'barcode' => $product->barcode,
                'barcodes' => $product->barcodes->pluck('barcode')->toArray(),
                'brand_name' => $product->brand?->name,
            ];
        })->values());
    }

    /**
     * Get orders for a specific product and user.
     */
    public function getProductOrders(Request $request): JsonResponse
    {
        $productId = $request->input('product_id');
        $userId = $request->input('user_id');

        if (!$productId || !$userId) {
            return response()->json([]);
        }

        // Получаем заказы пользователя, содержащие этот товар
        $orders = Order::where('user_id', $userId)
            ->whereHas('items', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })
            ->with(['items' => function ($query) use ($productId) {
                $query->where('product_id', $productId);
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) use ($productId) {
                $item = $order->items->first();
                return [
                    'id' => $order->id,
                    'uuid' => $order->uuid,
                    'label' => "Заказ #{$order->id} от " . $order->created_at->format('d.m.Y'),
                    'price' => $item?->price ?? 0,
                    'quantity' => $item?->quantity ?? 0,
                    'created_at' => $order->created_at->format('d.m.Y H:i'),
                ];
            });

        return response()->json($orders);
    }

    /**
     * Get the status label in Russian.
     */
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

    /**
     * Get the reason label in Russian.
     */
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

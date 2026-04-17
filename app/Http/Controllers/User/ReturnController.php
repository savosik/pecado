<?php

namespace App\Http\Controllers\User;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ReturnController extends Controller
{
    /**
     * Список возвратов текущего пользователя.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $query = ProductReturn::query()
            ->where('user_id', $user->id)
            ->with(['items']);

        // Поиск по UUID или ID
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%")
                    ->orWhere('id', $search);
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

        $allowedSortFields = ['id', 'total_amount', 'status', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $returns = $query->paginate($perPage)->withQueryString();

        // Трансформация данных
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
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.order_id' => 'nullable|exists:orders,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.reason' => 'required|string|in:'.implode(',', array_column(ReturnReason::cases(), 'value')),
            'items.*.reason_comment' => 'nullable|string',
        ], [
            'items.required' => 'Добавьте хотя бы одну позицию возврата.',
            'items.min' => 'Добавьте хотя бы одну позицию возврата.',
            'items.*.product_id.required' => 'Выберите товар.',
            'items.*.product_id.exists' => 'Выбранный товар не найден.',
            'items.*.quantity.required' => 'Укажите количество.',
            'items.*.quantity.min' => 'Количество должно быть не менее 1.',
            'items.*.price.required' => 'Укажите цену.',
            'items.*.reason.required' => 'Укажите причину возврата.',
            'items.*.reason.in' => 'Недопустимая причина возврата.',
        ]);

        // Проверяем что order_id принадлежат текущему пользователю
        foreach ($validated['items'] as $item) {
            if (! empty($item['order_id'])) {
                $order = Order::find($item['order_id']);
                if (! $order || $order->user_id !== $user->id) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->with('error', 'Указан заказ, не принадлежащий вашему аккаунту.');
                }
            }
        }

        DB::beginTransaction();
        try {
            $totalAmount = collect($validated['items'])->sum(function ($item) {
                return $item['price'] * $item['quantity'];
            });

            $return = ProductReturn::create([
                'user_id' => $user->id,
                'status' => ReturnStatus::PENDING->value,
                'comment' => $validated['comment'] ?? null,
                'total_amount' => $totalAmount,
            ]);

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

            // Отправка возврата в 1С через RabbitMQ (после commit, чтобы все данные были сохранены)
            event(new \App\Events\ReturnCreated($return));

            return redirect()
                ->route('cabinet.returns.show', $return)
                ->with('success', 'Заявка на возврат успешно создана.');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Ошибка при создании возврата: '.$e->getMessage());
        }
    }

    /**
     * Просмотр возврата.
     */
    public function show(Request $request, ProductReturn $return): InertiaResponse
    {
        $user = $request->user();
        abort_unless($return->user_id === $user->id, 403);

        $return->load(['items.product', 'items.order']);

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
                        'order' => $item->order ? [
                            'id' => $item->order->id,
                            'number' => $item->order->erp_number ?? $item->order->number ?? ('#'.$item->order->id),
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
     * Поиск товаров из заказов текущего пользователя.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $query = $request->input('query');
        $userId = $request->user()->id;

        if (! $query) {
            return response()->json([]);
        }

        $products = Product::search($query)
            ->query(fn ($q) => $q->with(['media', 'barcodes', 'brand'])->limit(20))
            ->get();

        // Фильтруем только товары из заказов пользователя
        $orderedProductIds = Order::where('user_id', $userId)
            ->with('items')
            ->get()
            ->flatMap(fn ($order) => $order->items->pluck('product_id'))
            ->unique()
            ->toArray();

        $products = $products->filter(fn ($product) => in_array($product->id, $orderedProductIds));

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
     * Получить заказы для товара текущего пользователя.
     */
    public function getProductOrders(Request $request): JsonResponse
    {
        $productId = $request->input('product_id');
        $userId = $request->user()->id;

        if (! $productId) {
            return response()->json([]);
        }

        $orders = Order::where('user_id', $userId)
            ->whereHas('items', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })
            ->with(['items' => function ($query) use ($productId) {
                $query->where('product_id', $productId);
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                $item = $order->items->first();

                return [
                    'id' => $order->id,
                    'number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                    'uuid' => $order->uuid,
                    'label' => 'Заказ '.($order->erp_number ?? $order->number ?? ('#'.$order->id)).' от '.$order->created_at->format('d.m.Y'),
                    'price' => $item?->price ?? 0,
                    'quantity' => $item?->quantity ?? 0,
                    'created_at' => $order->created_at->format('d.m.Y H:i'),
                ];
            });

        return response()->json($orders);
    }

    /**
     * Метка статуса на русском.
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
     * Метка причины на русском.
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

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class OrderController extends AdminController
{
    use RedirectsAfterSave;

    public function __construct(
        protected \App\Services\Pricing\PriceService $priceService
    ) {
        parent::__construct();
    }

    /**
     * Display a listing of orders.
     */
    public function index(Request $request): Response
    {
        $query = Order::query()
            ->with(['user', 'company', 'items']);

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

        // Фильтрация по компании
        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', $companyId);
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
        
        $allowedSortFields = ['id', 'uuid', 'total_amount', 'status', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $orders = $query->paginate($perPage)->withQueryString();

        // Трансформация данных для фронтенда
        $orders->getCollection()->transform(function ($order) {
            return [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'status_label' => $this->getStatusLabel($order->status),
                'total_amount' => $order->total_amount,
                'currency_code' => $order->currency_code ?? '₽',
                'created_at' => $order->created_at?->format('d.m.Y H:i'),
                'user' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->full_name,
                    'email' => $order->user->email,
                ] : null,
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                ] : null,
                'items' => $order->items,
            ];
        });

        return Inertia::render('Admin/Pages/Orders/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'company_id' => $companyId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'amount_from' => $amountFrom,
                'amount_to' => $amountTo,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
            'statuses' => collect(OrderStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
            ]),
            'companies' => Company::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Show the form for creating a new order.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Orders/Create', [
            'currencies' => \App\Models\Currency::select('id', 'name', 'code', 'symbol')->orderBy('id')->get(),
            'statuses' => collect(OrderStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
            ]),
        ]);
    }

    /**
     * Store a newly created order in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'company_id' => 'required|exists:companies,id',
            'delivery_address' => 'nullable|string|max:1000',
            'status' => 'required|string|in:' . implode(',', array_column(OrderStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'currency_code' => 'nullable|string|max:3',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.name' => 'required|string',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            // Подсчёт total_amount
            $totalAmount = collect($validated['items'])->sum(function ($item) {
                return $item['price'] * $item['quantity'];
            });

            // Создание заказа
            $order = Order::create([
                'user_id' => $validated['user_id'],
                'company_id' => $validated['company_id'],
                'delivery_address' => $validated['delivery_address'] ?? null,
                'status' => $validated['status'],
                'comment' => $validated['comment'] ?? null,
                'currency_code' => $validated['currency_code'] ?? 'RUB',
                'total_amount' => $totalAmount,
            ]);

            // Создание позиций заказа
            foreach ($validated['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['price'] * $item['quantity'],
                ]);
            }

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.orders.index', 'admin.orders.edit', $order, 'Заказ успешно создан');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при создании заказа: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(Order $order): Response
    {
        $order->load(['user', 'company', 'items.product', 'cart', 'statusHistories.user']);

        return Inertia::render('Admin/Pages/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'status_label' => $this->getStatusLabel($order->status),
                'total_amount' => $order->total_amount,
                'currency_code' => $order->currency_code,
                'comment' => $order->comment,
                'created_at' => $order->created_at?->format('d.m.Y H:i'),
                'updated_at' => $order->updated_at?->format('d.m.Y H:i'),
                'user' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->full_name,
                    'email' => $order->user->email,
                    'phone' => $order->user->phone,
                ] : null,
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                ] : null,
                'delivery_address' => $order->deliveryAddress ? [
                    'id' => $order->deliveryAddress->id,
                    'name' => $order->deliveryAddress->name,
                    'address' => $order->deliveryAddress->address,
                ] : null,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'price' => $item->price,
                        'quantity' => $item->quantity,
                        'subtotal' => $item->subtotal,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                        ] : null,
                    ];
                }),
                'status_histories' => $order->statusHistories->map(function ($history) {
                    return [
                        'id' => $history->id,
                        'old_status' => $history->old_status,
                        'new_status' => $history->new_status,
                        'old_status_label' => $history->old_status_label,
                        'new_status_label' => $history->new_status_label,
                        'user_name' => $history->user ? $history->user->full_name : 'Система',
                        'comment' => $history->comment,
                        'created_at' => $history->created_at->format('d.m.Y H:i'),
                        'created_at_human' => $history->created_at->diffForHumans(),
                    ];
                }),
            ],
            'statuses' => collect(OrderStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
            ]),
        ]);
    }

    /**
     * Show the form for editing the specified order.
     */
    public function edit(Order $order): Response
    {
        $order->load(['user', 'company', 'items.product.media', 'items.product.brand', 'statusHistories.user']);

        return Inertia::render('Admin/Pages/Orders/Edit', [
            'order' => [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'user_id' => $order->user_id,
                'company_id' => $order->company_id,
                'delivery_address' => $order->delivery_address,
                'status' => $order->status?->value,
                'comment' => $order->comment,
                'currency_code' => $order->currency_code,
                'total_amount' => $order->total_amount,
                'user' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->full_name,
                    'email' => $order->user->email,
                ] : null,
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                ] : null,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'name' => $item->name,
                        'price' => $item->price,
                        'quantity' => $item->quantity,
                        'subtotal' => $item->subtotal,
                        'sku' => $item->product ? $item->product->sku : null,
                        'image_url' => $item->product ? $item->product->getFirstMediaUrl('main') : null,
                        'brand_name' => $item->product && $item->product->brand ? $item->product->brand->name : null,
                    ];
                }),
                'status_histories' => $order->statusHistories->map(function ($history) {
                    return [
                        'id' => $history->id,
                        'old_status' => $history->old_status,
                        'new_status' => $history->new_status,
                        'old_status_label' => $history->old_status_label,
                        'new_status_label' => $history->new_status_label,
                        'user_name' => $history->user ? $history->user->full_name : 'Система',
                        'comment' => $history->comment,
                        'created_at' => $history->created_at->format('d.m.Y H:i'),
                        'created_at_human' => $history->created_at->diffForHumans(),
                    ];
                }),
            ],
            'currencies' => \App\Models\Currency::select('id', 'name', 'code', 'symbol')->orderBy('id')->get(),
            'statuses' => collect(OrderStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $this->getStatusLabel($case),
            ]),
        ]);
    }

    /**
     * Update the specified order in storage.
     */
    public function update(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'company_id' => 'required|exists:companies,id',
            'delivery_address' => 'nullable|string|max:1000',
            'status' => 'required|string|in:' . implode(',', array_column(OrderStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'currency_code' => 'nullable|string|max:3',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.name' => 'required|string',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            // Подсчёт total_amount
            $totalAmount = collect($validated['items'])->sum(function ($item) {
                return $item['price'] * $item['quantity'];
            });

            // Обновление заказа
            $order->update([
                'user_id' => $validated['user_id'],
                'company_id' => $validated['company_id'],
                'delivery_address' => $validated['delivery_address'] ?? null,
                'status' => $validated['status'],
                'comment' => $validated['comment'] ?? null,
                'currency_code' => $validated['currency_code'] ?? 'RUB',
                'total_amount' => $totalAmount,
            ]);

            // Синхронизация позиций заказа
            $existingItemIds = [];
            foreach ($validated['items'] as $item) {
                if (!empty($item['id'])) {
                    // Обновление существующей позиции
                    $orderItem = $order->items()->find($item['id']);
                    if ($orderItem) {
                        $orderItem->update([
                            'product_id' => $item['product_id'],
                            'name' => $item['name'],
                            'price' => $item['price'],
                            'quantity' => $item['quantity'],
                            'subtotal' => $item['price'] * $item['quantity'],
                        ]);
                        $existingItemIds[] = $item['id'];
                    }
                } else {
                    // Создание новой позиции
                    $newItem = $order->items()->create([
                        'product_id' => $item['product_id'],
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'quantity' => $item['quantity'],
                        'subtotal' => $item['price'] * $item['quantity'],
                    ]);
                    $existingItemIds[] = $newItem->id;
                }
            }

            // Удаление позиций, которых больше нет
            $order->items()->whereNotIn('id', $existingItemIds)->delete();

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.orders.index', 'admin.orders.edit', $order, 'Заказ успешно обновлён');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при обновлении заказа: ' . $e->getMessage()]);
        }
    }

    /**
     * Update the status of the specified order.
     */
    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', array_column(OrderStatus::cases(), 'value')),
        ]);

        $order->update([
            'status' => $validated['status'],
        ]);

        return redirect()
            ->back()
            ->with('success', 'Статус заказа успешно обновлён');
    }

    /**
     * Bulk update status for multiple orders.
     */
    public function bulkUpdateStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'status' => 'required|string|in:' . implode(',', array_column(OrderStatus::cases(), 'value')),
        ]);

        $count = Order::whereIn('id', $validated['order_ids'])->update([
            'status' => $validated['status'],
        ]);

        return redirect()
            ->back()
            ->with('success', "Статус обновлён для {$count} заказов");
    }

    /**
     * Remove the specified order from storage (soft delete).
     */
    public function destroy(Order $order): RedirectResponse
    {
        $order->delete();

        return redirect()->route('admin.orders.index')->with('success', 'Заказ успешно удалён');
    }

    /**
     * Calculate price for a product based on user and currency.
     */
    public function calculatePrice(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'user_id' => 'nullable|exists:users,id',
            'currency_code' => 'nullable|string|exists:currencies,code',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $user = $validated['user_id'] ? User::find($validated['user_id']) : null;
        $currencyCode = $validated['currency_code'] ?? 'RUB';
        
        // 1. Get price in base currency (with user discounts applied)
        if ($user) {
            $basePrice = $this->priceService->getDiscountedPrice($product, $user);
        } else {
            $basePrice = $product->base_price;
        }

        // 2. Convert to target currency
        $finalPrice = $basePrice;
        if ($currencyCode !== 'RUB') { 
             $currency = \App\Models\Currency::where('code', $currencyCode)->first();
             if ($currency) {
                 $finalPrice = $this->priceService->convertPrice($basePrice, $currency);
             }
        }
        
        // Round to 2 decimals
        $finalPrice = round($finalPrice, 2);

        return response()->json([
            'price' => $finalPrice,
            'currency_code' => $currencyCode,
        ]);
    }

    /**
     * Search users for async selector.
     */
    public function searchUsers(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query', '');
        
        $users = User::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('surname', 'like', "%{$query}%")
                  ->orWhere('patronymic', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->select('id', 'name', 'surname', 'patronymic', 'email')
            ->orderBy('surname')
            ->limit(20)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'label' => "{$user->full_name} ({$user->email})",
                ];
            });
            
        return response()->json($users);
    }

    /**
     * Search companies for async selector.
     * Optionally filter by user_id.
     */
    public function searchCompanies(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query', '');
        $userId = $request->input('user_id');
        
        $companies = Company::query()
            ->when($userId, function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->when($query, function ($q) use ($query) {
                $q->where(function ($subQ) use ($query) {
                    $subQ->where('name', 'like', "%{$query}%")
                         ->orWhere('legal_name', 'like', "%{$query}%")
                         ->orWhere('tax_id', 'like', "%{$query}%");
                });
            })
            ->with('user:id,name,surname,patronymic,email')
            ->select('id', 'name', 'user_id')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(function ($company) {
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'user_id' => $company->user_id,
                    'user_name' => $company->user?->full_name,
                    'user_email' => $company->user?->email,
                    'label' => $company->name,
                ];
            });
            
        return response()->json($companies);
    }

    /**
     * Get the status label in Russian.
     */
    protected function getStatusLabel(?OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::PENDING => 'Ожидает',
            OrderStatus::PROCESSING => 'В обработке',
            OrderStatus::SHIPPED => 'Отправлен',
            OrderStatus::DELIVERED => 'Доставлен',
            OrderStatus::CANCELLED => 'Отменён',
            default => 'Неизвестно',
        };
    }
}

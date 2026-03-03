<?php

namespace App\Http\Controllers\User;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class OrderController extends Controller
{
    /**
     * Список заказов текущего пользователя.
     * GET /cabinet/orders
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $query = Order::query()
            ->where('user_id', $user->id)
            ->whereNull('parent_id')
            ->with(['company', 'items']);

        // Поиск по UUID или ID
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                  ->orWhere('id', $search);
            });
        }

        // Фильтрация по статусу
        if ($status = $request->input('status')) {
            $query->where('status', $status);
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

        $orders = $query->paginate($perPage)->withQueryString();

        // Трансформация данных
        $orders->getCollection()->transform(function ($order) {
            return [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'status_label' => $this->getStatusLabel($order->status),
                'total_amount' => $order->total_amount,
                'currency_code' => $order->currency_code ?? '₽',
                'created_at' => $order->created_at?->format('d.m.Y H:i'),
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                ] : null,
                'items_count' => $order->items->count(),
            ];
        });

        return Inertia::render('User/Cabinet/Orders/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $status,
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
        ]);
    }

    /**
     * Просмотр заказа.
     * GET /cabinet/orders/{order}
     */
    public function show(Request $request, Order $order): InertiaResponse
    {
        $user = $request->user();

        // Убедиться, что заказ принадлежит текущему пользователю
        abort_unless($order->user_id === $user->id, 403);

        // Загрузить все связи
        $order->load([
            'company:id,name,legal_name,tax_id',
            'deliveryAddress:id,name,address',
            'items.product:id,name,sku,slug',
            'items.product.brand:id,name',
            'items.product.media',
            'children.items.product:id,name,sku,slug',
            'children.items.product.brand:id,name',
            'statusHistories.user',
        ]);

        // Подготовить данные дочерних заказов
        $childOrders = $order->children->map(function ($child) {
            return [
                'id' => $child->id,
                'type' => $child->type?->value,
                'status' => $child->status?->value,
                'status_label' => $this->getStatusLabel($child->status),
                'total_amount' => $child->total_amount,
                'items' => $child->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'price' => $item->price,
                        'quantity' => $item->quantity,
                        'subtotal' => $item->subtotal,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                            'slug' => $item->product->slug,
                            'brand' => $item->product->brand ? [
                                'name' => $item->product->brand->name,
                            ] : null,
                        ] : null,
                    ];
                }),
            ];
        });

        return Inertia::render('User/Cabinet/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'status_label' => $this->getStatusLabel($order->status),
                'type' => $order->type?->value,
                'comment' => $order->comment,
                'total_amount' => $order->total_amount,
                'currency_code' => $order->currency_code,
                'created_at' => $order->created_at?->toISOString(),
                'created_at_formatted' => $order->created_at?->format('d.m.Y H:i'),
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                    'legal_name' => $order->company->legal_name,
                    'tax_id' => $order->company->tax_id,
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
                            'sku' => $item->product->sku,
                            'slug' => $item->product->slug,
                            'image_url' => $item->product->getFirstMediaUrl('main'),
                            'brand' => $item->product->brand ? [
                                'name' => $item->product->brand->name,
                            ] : null,
                        ] : null,
                    ];
                }),
                'children' => $childOrders,
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
     * Получить метку статуса на русском.
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

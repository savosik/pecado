<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class ShipmentController extends Controller
{
    private const STATUS_LABELS = [
        'new' => 'Новая',
        'completed' => 'Выполнена',
        'cancelled' => 'Отменена',
        'in_progress' => 'В обработке',
    ];

    public function index(Request $request)
    {
        $onlyTrashed = $request->boolean('trashed');

        $query = $onlyTrashed
            ? Shipment::onlyTrashed()->with(['user', 'company', 'items.product'])
            : Shipment::query()->with(['user', 'company', 'items.product']);

        // Поиск по UUID, ИНН, наименованию компании или товару
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")
                    ->orWhereHas('company', function ($cq) use ($search) {
                        $cq->withoutGlobalScopes()
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('legal_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items.product', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
            });
        }

        // Фильтр по статусу
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Фильтр по пользователю
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Фильтр по дате
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('date', '<=', $dateTo);
        }

        // Фильтр по валюте
        if ($currency = $request->input('currency_code')) {
            $query->where('currency_code', $currency);
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowed = ['id', 'date', 'total_amount', 'status', 'created_at'];
        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $shipments = $query->paginate($perPage)->withQueryString();

        // Трансформация данных
        $shipments->getCollection()->transform(function ($shipment) {
            return [
                'id' => $shipment->id,
                'uuid' => $shipment->uuid,
                'number' => $shipment->number,
                'tax_id' => $shipment->tax_id,
                'date' => $shipment->date?->format('Y-m-d'),
                'status' => $shipment->status,
                'status_label' => self::STATUS_LABELS[$shipment->status] ?? $shipment->status,
                'currency_code' => $shipment->currency_code,
                'total_amount' => $shipment->total_amount,
                'items_count' => $shipment->items->count(),
                'created_at' => $shipment->created_at->format('d.m.Y H:i'),
                'deleted_at' => $shipment->deleted_at?->format('d.m.Y H:i'),
                'erp_created_at' => $shipment->erp_created_at?->format('d.m.Y H:i'),
                'erp_updated_at' => $shipment->erp_updated_at?->format('d.m.Y H:i'),
                'user' => $shipment->user ? [
                    'id' => $shipment->user->id,
                    'name' => $shipment->user->name,
                    'email' => $shipment->user->email,
                ] : null,
                'company' => $shipment->company ? [
                    'id' => $shipment->company->id,
                    'name' => $shipment->company->name,
                ] : null,
            ];
        });

        return Inertia::render('Admin/Pages/Shipments/Index', [
            'shipments' => $shipments,
            'filters' => array_merge(
                $request->only(['search', 'status', 'user_id', 'date_from', 'date_to', 'currency_code', 'sort_by', 'sort_order', 'per_page']),
                ['trashed' => $onlyTrashed]
            ),
            'trashedCount' => Shipment::onlyTrashed()->count(),
            'statuses' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(self::STATUS_LABELS), self::STATUS_LABELS),
        ]);
    }

    public function show(Shipment $shipment)
    {
        $shipment->load(['user', 'company', 'items.product', 'items.order']);

        // Получить связанные заказы с расширенными данными для карточного отображения
        $orderUuids = $shipment->items()
            ->whereNotNull('order_uuid')
            ->pluck('order_uuid')
            ->unique()
            ->values();

        $relatedOrders = $orderUuids->isNotEmpty()
            ? Order::withoutGlobalScopes()
                ->whereIn('uuid', $orderUuids)
                ->with(['company:id,name', 'user:id,name,email'])
                ->withCount(['items', 'shipments'])
                ->orderByDesc('created_at')
                ->get()
            : collect();

        return Inertia::render('Admin/Pages/Shipments/Show', [
            'shipment' => [
                'id' => $shipment->id,
                'uuid' => $shipment->uuid,
                'number' => $shipment->number,
                'tax_id' => $shipment->tax_id,
                'date' => $shipment->date?->format('Y-m-d'),
                'status' => $shipment->status,
                'status_label' => self::STATUS_LABELS[$shipment->status] ?? $shipment->status,
                'currency_code' => $shipment->currency_code,
                'total_amount' => $shipment->total_amount,
                'created_at' => $shipment->created_at->format('d.m.Y H:i'),
                'erp_created_at' => $shipment->erp_created_at?->format('d.m.Y H:i'),
                'erp_updated_at' => $shipment->erp_updated_at?->format('d.m.Y H:i'),
                'user' => $shipment->user ? [
                    'id' => $shipment->user->id,
                    'name' => $shipment->user->name,
                    'email' => $shipment->user->email,
                ] : null,
                'company' => $shipment->company ? [
                    'id' => $shipment->company->id,
                    'name' => $shipment->company->name,
                ] : null,
                'items' => $shipment->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'order_uuid' => $item->order_uuid,
                        'order_number' => $item->order?->number,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'auto_discount_percent' => $item->auto_discount_percent,
                        'manual_discount_percent' => $item->manual_discount_percent,
                        'total' => $item->total,
                        'subtotal' => $item->subtotal,
                        'vat_rate' => $item->vat_rate,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                        ] : null,
                    ];
                }),
            ],
            'related_orders' => $relatedOrders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'uuid' => $order->uuid,
                    'number' => $order->number,
                    'erp_number' => $order->erp_number,
                    'type' => $order->type?->value,
                    'type_label' => $order->type?->value === 'preorder' ? 'Предзаказ' : 'Заказ со склада',
                    'status' => $order->status?->value,
                    'status_label' => match ($order->status) {
                        OrderStatus::PENDING => 'Ожидает',
                        OrderStatus::CONFIRMED => 'Подтверждён',
                        OrderStatus::READY_TO_SHIP => 'К отгрузке',
                        OrderStatus::CLOSED => 'Закрыт',
                        OrderStatus::DELETED => 'Удалён',
                        default => 'Неизвестно',
                    },
                    'total_amount' => $order->total_amount,
                    'currency_code' => $order->currency_code,
                    'items_count' => $order->items_count,
                    'shipments_count' => $order->shipments_count,
                    'delivery_address' => $order->delivery_address,
                    'comment' => $order->comment,
                    'created_at' => $order->created_at?->format('d.m.Y H:i'),
                    'erp_created_at' => $order->erp_created_at?->format('d.m.Y H:i'),
                    'company' => $order->company ? [
                        'id' => $order->company->id,
                        'name' => $order->company->name,
                    ] : null,
                    'user' => $order->user ? [
                        'id' => $order->user->id,
                        'name' => $order->user->name,
                        'email' => $order->user->email,
                    ] : null,
                ];
            }),
        ]);
    }

    public function destroy(Shipment $shipment)
    {
        $shipment->delete();

        return redirect()->route('admin.shipments.index')->with('success', 'Реализация успешно удалена');
    }

    public function forceDestroy(int $id)
    {
        $shipment = Shipment::onlyTrashed()->findOrFail($id);
        $shipment->forceDelete();

        return redirect()->route('admin.shipments.index', ['trashed' => 1])->with('success', 'Реализация окончательно удалена');
    }
}

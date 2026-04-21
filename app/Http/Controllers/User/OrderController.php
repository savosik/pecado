<?php

namespace App\Http\Controllers\User;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Order;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class OrderController extends Controller
{
    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    /**
     * Список заказов текущего пользователя.
     * GET /cabinet/orders
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['company'])
            ->withCount(['items', 'shipments']);

        // Поиск по UUID или ID
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        // Фильтрация по типу
        if ($type = $request->input('type')) {
            $query->where('type', $type);
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

        // Валюта пользователя для конвертации
        $currency = $this->getUserCurrency($request);

        // Трансформация данных
        $orders->getCollection()->transform(function ($order) use ($currency) {
            $totalConverted = $this->convertAmount((float) $order->total_amount, $order->currency_code, $currency);

            return [
                'id' => $order->id,
                'number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'status_label' => $this->getStatusLabel($order->status),
                'type' => $order->type?->value,
                'total_amount' => $order->total_amount,
                'total_converted' => $totalConverted,
                'currency_code' => $order->currency_code,
                'created_at' => $order->created_at?->format('d.m.Y H:i'),
                'updated_at' => $order->updated_at?->format('d.m.Y H:i'),
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                ] : null,
                'items_count' => $order->items_count,
                'shipments_count' => $order->shipments_count,
                'delivery_address' => $order->delivery_address,
                'comment' => $order->comment,
            ];
        });

        return Inertia::render('User/Cabinet/Orders/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'type' => $request->input('type', ''),
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
            'types' => [
                ['value' => 'order',    'label' => 'Заказ со склада'],
                ['value' => 'preorder', 'label' => 'Предзаказ'],
            ],
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
            'items.product:id,name,sku,slug',
            'items.product.brand:id,name',
            'items.product.media',
            'statusHistories.user',
            'changeLogs.user',
            'shipments',
        ]);

        return Inertia::render('User/Cabinet/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'status_label' => $this->getStatusLabel($order->status),
                'type' => $order->type?->value,
                'comment' => $order->comment,
                'total_amount' => $order->total_amount,
                'total_converted' => $this->convertAmount((float) $order->total_amount, $order->currency_code, $this->getUserCurrency($request)),
                'currency_code' => $order->currency_code,
                'created_at' => $order->created_at?->toISOString(),
                'created_at_formatted' => $order->created_at?->format('d.m.Y H:i'),
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                    'legal_name' => $order->company->legal_name,
                    'tax_id' => $order->company->tax_id,
                ] : null,
                'delivery_address' => $order->delivery_address,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'price' => $item->price,
                        'base_price' => $item->base_price,
                        'final_price' => $item->final_price,
                        'discount_percent' => $item->discount_percent,
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

                'shipments' => $order->shipments->map(function ($shipment) {
                    return [
                        'id' => $shipment->id,
                        'number' => $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id),
                        'uuid' => $shipment->uuid,
                        'date' => $shipment->date?->format('Y-m-d'),
                        'status' => $shipment->status,
                        'status_label' => match ($shipment->status) {
                            'completed' => 'Выполнена',
                            'in_progress' => 'В обработке',
                            'new' => 'Новая',
                            'cancelled' => 'Отменена',
                            default => $shipment->status,
                        },
                        'total_amount' => $shipment->total_amount,
                        'currency_code' => $shipment->currency_code,
                        'items_count' => $shipment->items()->count(),
                    ];
                }),
                'status_histories' => $order->statusHistories->map(function ($history) {
                    return [
                        'id' => $history->id,
                        'old_status' => $history->old_status,
                        'new_status' => $history->new_status,
                        'old_status_label' => $history->old_status_label,
                        'new_status_label' => $history->new_status_label,
                        'user_name' => $history->user ? $history->user->name : 'Система',
                        'comment' => $history->comment,
                        'created_at' => $history->created_at->format('d.m.Y H:i'),
                        'created_at_human' => $history->created_at->locale('ru')->diffForHumans(),
                    ];
                }),
                'change_logs' => $order->changeLogs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'type' => $log->type,
                        'summary' => $log->summary,
                        'changes' => $log->changes,
                        'source' => $log->source,
                        'user_name' => $log->user?->name,
                        'old_total' => $log->old_total,
                        'new_total' => $log->new_total,
                        'created_at' => $log->created_at->format('d.m.Y H:i'),
                        'created_at_human' => $log->created_at->locale('ru')->diffForHumans(),
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
            OrderStatus::CONFIRMED => 'Подтверждён',
            OrderStatus::READY_TO_SHIP => 'К отгрузке',
            OrderStatus::CLOSED => 'Закрыт',
            OrderStatus::DELETED => 'Удалён',
            default => 'Неизвестно',
        };
    }

    /**
     * Получить текущую валюту пользователя через регион.
     */
    private function getUserCurrency(Request $request): ?Currency
    {
        return $request->user()?->region?->currency;
    }

    /**
     * Конвертировать сумму из валюты заказа в валюту пользователя.
     */
    private function convertAmount(float $amount, ?string $sourceCurrencyCode, ?Currency $targetCurrency): float
    {
        if (! $targetCurrency || $targetCurrency->is_base) {
            if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
                $src = Currency::where('code', $sourceCurrencyCode)->first();
                if ($src) {
                    return round($amount * (float) $src->exchange_rate, 2);
                }
            }

            return $amount;
        }

        // Сначала в RUB, потом в целевую валюту
        $amountInRub = $amount;
        if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
            $src = Currency::where('code', $sourceCurrencyCode)->first();
            if ($src) {
                $amountInRub = round($amount * (float) $src->exchange_rate, 2);
            }
        }

        return $this->currencyService->convertFromBase($amountInRub, $targetCurrency);
    }
}

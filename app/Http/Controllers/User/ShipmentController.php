<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ContractorBalance;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\Shipment;
use App\Services\CurrencyService;
use App\Models\Currency;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ShipmentController extends Controller
{
    private const STATUS_LABELS = [
        'new'         => 'Новая',
        'completed'   => 'Выполнена',
        'cancelled'   => 'Отменена',
        'in_progress' => 'В обработке',
    ];

    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    /**
     * Список отгрузок текущего пользователя.
     * GET /cabinet/shipments
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $query = Shipment::query()
            ->where('user_id', $user->id)
            ->with(['company', 'items.product']);

        // Поиск по UUID, ИНН или названию товара
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('contractor_inn', 'like', "%{$search}%")
                    ->orWhereHas('items.product', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
            });
        }

        // Фильтр по статусу
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Фильтр по дате
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('date', '<=', $dateTo);
        }

        // Фильтр по сумме
        if ($amountFrom = $request->input('amount_from')) {
            $query->where('total_amount', '>=', $amountFrom);
        }
        if ($amountTo = $request->input('amount_to')) {
            $query->where('total_amount', '<=', $amountTo);
        }

        // Сортировка
        $sortBy    = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowed = ['id', 'date', 'total_amount', 'status'];
        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage   = min(max((int) $request->input('per_page', 15), 5), 100);
        $shipments = $query->paginate($perPage)->withQueryString();

        // Получить текущую валюту пользователя
        $currency = $this->getUserCurrency($request);

        // Трансформация данных
        $shipments->getCollection()->transform(function ($shipment) use ($currency) {
            $totalConverted = $this->convertAmount((float)$shipment->total_amount, $shipment->currency_code, $currency);

            return [
                'id'              => $shipment->id,
                'number'          => $shipment->number ?? ('#' . $shipment->id),
                'contractor_inn'  => $shipment->contractor_inn,
                'date'            => $shipment->date?->format('Y-m-d'),
                'updated_at'      => $shipment->updated_at?->format('d.m.Y H:i'),
                'status'          => $shipment->status,
                'status_label'    => self::STATUS_LABELS[$shipment->status] ?? $shipment->status,
                'currency_code'   => $shipment->currency_code,
                'total_amount'    => $shipment->total_amount,
                'total_converted' => $totalConverted,
                'items_count'     => $shipment->items->count(),
                'company'         => $shipment->company ? [
                    'id'   => $shipment->company->id,
                    'name' => $shipment->company->name,
                ] : null,
            ];
        });

        return Inertia::render('User/Cabinet/Shipments/Index', [
            'shipments' => $shipments,
            'filters'   => [
                'search'      => $search,
                'status'      => $status,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'amount_from' => $amountFrom,
                'amount_to'   => $amountTo,
                'sort_by'     => $sortBy,
                'sort_order'  => $sortOrder,
                'per_page'    => $perPage,
            ],
            'statuses'  => array_map(
                fn ($k, $v) => ['value' => $k, 'label' => $v],
                array_keys(self::STATUS_LABELS),
                self::STATUS_LABELS
            ),
        ]);
    }

    /**
     * Просмотр отгрузки.
     * GET /cabinet/shipments/{shipment}
     */
    public function show(Request $request, Shipment $shipment): InertiaResponse
    {
        $user = $request->user();

        abort_unless($shipment->user_id === $user->id, 403);

        $shipment->load(['company', 'items.product', 'items.product.brand']);

        $currency = $this->getUserCurrency($request);

        $relatedOrders = $shipment->getRelatedOrders();

        $totalConverted = $this->convertAmount((float)$shipment->total_amount, $shipment->currency_code, $currency);

        // Получить деталь просрочки для этой реализации (если есть)
        $overdueDetail = null;
        if ($shipment->uuid) {
            $overdueDetail = ContractorBalanceOverdueDetail::whereHas(
                'contractorBalance',
                fn($q) => $q->where('user_id', $user->id)
            )->where('shipment_uuid', $shipment->uuid)->first();
        }

        return Inertia::render('User/Cabinet/Shipments/Show', [
            'shipment'       => [
                'id'              => $shipment->id,
                'number'          => $shipment->number ?? ('#' . $shipment->id),
                'uuid'            => $shipment->uuid,
                'contractor_inn'  => $shipment->contractor_inn,
                'date'            => $shipment->date?->format('Y-m-d'),
                'updated_at'      => $shipment->updated_at?->format('d.m.Y H:i'),
                'status'          => $shipment->status,
                'status_label'    => self::STATUS_LABELS[$shipment->status] ?? $shipment->status,
                'currency_code'   => $shipment->currency_code,
                'total_amount'    => $shipment->total_amount,
                'total_converted' => $totalConverted,
                'company'         => $shipment->company ? [
                    'id'         => $shipment->company->id,
                    'name'       => $shipment->company->name,
                    'legal_name' => $shipment->company->legal_name,
                    'tax_id'     => $shipment->company->tax_id,
                ] : null,
                'items'           => $shipment->items->map(function ($item) use ($currency) {
                    $priceConverted = $this->convertAmount((float)$item->price, null, $currency);
                    $totalConverted = $this->convertAmount((float)$item->total, null, $currency);

                    return [
                        'id'                      => $item->id,
                        'order_uuid'              => $item->order_uuid,
                        'quantity'                => $item->quantity,
                        'price'                   => $item->price,
                        'price_converted'         => $priceConverted,
                        'auto_discount_percent'   => $item->auto_discount_percent,
                        'manual_discount_percent' => $item->manual_discount_percent,
                        'total'                   => $item->total,
                        'total_converted'         => $totalConverted,
                        'vat_rate'                => $item->vat_rate,
                        'product'                 => $item->product ? [
                            'id'        => $item->product->id,
                            'name'      => $item->product->name,
                            'sku'       => $item->product->sku,
                            'slug'      => $item->product->slug,
                            'image_url' => $item->product->getFirstMediaUrl('main'),
                            'brand'     => $item->product->brand ? ['name' => $item->product->brand->name] : null,
                        ] : null,
                    ];
                }),
            ],
            'related_orders' => $relatedOrders->map(function ($order) {
                return [
                    'id'     => $order->id,
                    'number' => $order->number ?? ('#' . $order->id),
                    'status' => $order->status?->value,
                ];
            }),
            'overdue_detail'  => $overdueDetail ? [
                'shipment_uuid' => $overdueDetail->shipment_uuid,
                'amount'        => $overdueDetail->amount,
                'due_date'      => $overdueDetail->due_date?->format('Y-m-d'),
            ] : null,
        ]);
    }

    /**
     * Получить текущую валюту пользователя через регион.
     */
    private function getUserCurrency(Request $request): ?Currency
    {
        return $request->user()?->region?->currency;
    }

    /**
     * Конвертировать сумму в валюту пользователя.
     * Отгрузки хранятся в валюте 1С, но отображаются в валюте пользователя.
     */
    private function convertAmount(float $amount, ?string $sourceCurrencyCode, ?Currency $targetCurrency): float
    {
        if (!$targetCurrency || $targetCurrency->is_base) {
            // Если целевая валюта — базовая (RUB), возвращаем как есть
            // Но если источник не RUB — нужно конвертировать в RUB через курс источника
            if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
                $sourceCurrency = Currency::where('code', $sourceCurrencyCode)->first();
                if ($sourceCurrency) {
                    // amount in source_currency → RUB: amount * exchange_rate
                    return round($amount * (float)$sourceCurrency->exchange_rate, 2);
                }
            }
            return $amount;
        }

        // Сначала перевести в RUB (если источник не RUB)
        $amountInRub = $amount;
        if ($sourceCurrencyCode && $sourceCurrencyCode !== 'RUB') {
            $sourceCurrency = Currency::where('code', $sourceCurrencyCode)->first();
            if ($sourceCurrency) {
                $amountInRub = round($amount * (float)$sourceCurrency->exchange_rate, 2);
            }
        }

        // Затем конвертировать из RUB в целевую валюту
        return $this->currencyService->convertFromBase($amountInRub, $targetCurrency);
    }
}

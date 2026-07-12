<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientApiController extends Controller
{
    public function __construct(
        protected PriceServiceInterface $priceService,
        protected StockServiceInterface $stockService,
        protected UserCurrencyResolverInterface $currencyResolver
    ) {}

    /**
     * GET /api/client-api/{token}/prices
     * Получить цены пользователя.
     *
     * Цены конвертируются в валюту региона пользователя.
     * Опциональный GET-параметр ?currency=BYN для явного указания валюты.
     */
    public function prices(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        // Определяем целевую валюту: явный параметр или валюта региона
        $currency = null;
        if ($currencyCode = $request->query('currency')) {
            $currency = \App\Models\Currency::where('code', $currencyCode)->first();
        }
        if (! $currency) {
            $currency = $this->currencyResolver->resolve($user);
        }

        $currencyService = app(\App\Services\CurrencyService::class);

        $perPage = min((int) $request->input('per_page', 500), 1000);

        $products = Product::query()
            ->select('id', 'external_id', 'code', 'sku', 'barcode', 'base_price', 'name')
            ->orderBy('id')
            ->paginate($perPage);

        $data = $products->getCollection()->map(function (Product $product) use ($user, $currency, $currencyService) {
            $priceResult = $this->priceService->getPriceResult($product, $user);

            $basePrice = round($priceResult->basePrice, 2);
            $displayPrice = round($priceResult->getDisplayPrice(), 2);

            // Конвертация в целевую валюту
            if ($currency && ! $currency->is_base) {
                $basePrice = $currencyService->convertFromBase($basePrice, $currency);
                $displayPrice = $currencyService->convertFromBase($displayPrice, $currency);
            }

            return [
                'uuid' => $product->external_id,
                'code' => $product->code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'base_price' => $basePrice,
                'price' => $displayPrice,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'currency_code' => $currency?->code ?? 'RUB',
                'currency_symbol' => $currency?->symbol ?? '₽',
            ],
        ]);
    }

    /**
     * GET /api/client-api/{token}/stocks
     * Получить остатки по региону пользователя.
     */
    public function stocks(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        $perPage = min((int) $request->input('per_page', 500), 1000);

        $products = Product::query()
            ->select('id', 'external_id', 'code', 'sku', 'barcode', 'name')
            ->orderBy('id')
            ->paginate($perPage);

        $data = $products->getCollection()->map(function (Product $product) use ($user) {
            $stock = $this->stockService->getStock($product, $user);

            return [
                'uuid' => $product->external_id,
                'code' => $product->code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'available' => $stock['available'],
                'preorder' => $stock['preorder'],
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * POST /api/client-api/{token}/orders
     * Создать заказ (с проверкой остатков и разделением на заказ/предзаказ).
     */
    public function orders(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        $validated = $request->validate([
            'inn' => 'required|string|max:12',
            'address' => 'nullable|string|max:500',
            'delivery_method' => 'nullable|in:delivery,pickup',
            'comment' => 'nullable|string|max:1000',
            'products' => 'required|array|min:1',
            'products.*.identifier' => 'required|string|max:255',
            'products.*.quantity' => 'required|integer|min:1',
        ], [
            'inn.required' => 'ИНН обязателен',
            'delivery_method.in' => 'Недопустимый способ доставки. Допустимые значения: delivery, pickup',
            'products.required' => 'Список товаров обязателен',
            'products.min' => 'Список товаров не может быть пустым',
            'products.*.identifier.required' => 'Идентификатор товара обязателен',
            'products.*.quantity.required' => 'Количество обязательно',
            'products.*.quantity.min' => 'Количество должно быть не менее 1',
        ]);

        // Найти компанию по ИНН
        $company = $user->companies()->where('tax_id', $validated['inn'])->first();
        if (! $company) {
            return response()->json([
                'error' => 'Компания с указанным ИНН не найдена в вашем аккаунте',
                'inn' => $validated['inn'],
            ], 422);
        }

        // Резолвим товары и раскладываем на выполнимую часть.
        // Дружественная логика: заказ принимается даже если часть позиций
        // недоступна. Недостающее не блокирует заказ, а попадает в
        // информационный ответ (unavailable — не попали в заказ вовсе,
        // partial — отгружены не в полном объёме).
        $instockItems = [];
        $preorderItems = [];
        $unavailable = [];
        $partial = [];

        foreach ($validated['products'] as $item) {
            $requestedQty = $item['quantity'];

            $product = $this->resolveProduct($item['identifier']);
            if (! $product) {
                $unavailable[] = [
                    'identifier' => $item['identifier'],
                    'requested' => $requestedQty,
                    'reason' => 'not_found',
                    'message' => 'Товар не найден',
                ];

                continue;
            }

            $stock = $this->stockService->getStock($product, $user);
            $available = $stock['available'];
            $preorder = $stock['preorder'];
            $totalAvailable = $available + $preorder;

            if ($totalAvailable <= 0) {
                $unavailable[] = [
                    'identifier' => $item['identifier'],
                    'name' => $product->name,
                    'requested' => $requestedQty,
                    'reason' => 'out_of_stock',
                    'message' => 'Нет в наличии',
                ];

                continue;
            }

            // Отгружаем столько, сколько реально доступно; остаток запроса — в shortfall.
            $fulfillQty = min($requestedQty, $totalAvailable);
            $instockQty = min($fulfillQty, $available);
            $preorderQty = $fulfillQty - $instockQty;

            if ($instockQty > 0) {
                $instockItems[] = ['product' => $product, 'quantity' => $instockQty];
            }
            if ($preorderQty > 0) {
                $preorderItems[] = ['product' => $product, 'quantity' => $preorderQty];
            }

            if ($fulfillQty < $requestedQty) {
                $partial[] = [
                    'identifier' => $item['identifier'],
                    'name' => $product->name,
                    'requested' => $requestedQty,
                    'fulfilled' => $fulfillQty,
                    'shortfall' => $requestedQty - $fulfillQty,
                ];
            }
        }

        // Совсем нечего отгружать — заказ не создаём.
        if (empty($instockItems) && empty($preorderItems)) {
            return response()->json([
                'error' => 'Ни одна из позиций недоступна для заказа',
                'unavailable' => $unavailable,
            ], 422);
        }

        // Валюта пользователя (как в CheckoutService)
        $currency = $this->currencyResolver->resolve($user);

        // Дополняем комментарий системной пометкой о недоступных/частичных
        // позициях, чтобы менеджер и 1С видели, что клиент запрашивал больше.
        $comment = $validated['comment'] ?? null;
        if ($note = $this->buildFulfillmentNote($unavailable, $partial)) {
            $comment = $comment !== null && $comment !== '' ? ($comment."\n\n".$note) : $note;
        }

        // Способ доставки (v15.3): delivery по умолчанию; при самовывозе адрес не хранится
        $deliveryMethod = $validated['delivery_method'] ?? DeliveryMethod::DELIVERY->value;

        $baseOrderData = [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'delivery_address' => $deliveryMethod === DeliveryMethod::PICKUP->value
                ? null
                : ($validated['address'] ?? null),
            'delivery_method' => $deliveryMethod,
            'status' => OrderStatus::PENDING_APPROVAL,
            'comment' => $comment,
            'total_amount' => 0,
            'exchange_rate' => $currency?->exchange_rate ?? 1.0,
            'rate_coefficient' => $currency?->rate_coefficient ?? 1.0,
            'currency_code' => $currency?->code ?? 'RUB',
        ];

        // Создать заказ(ы) в транзакции
        $createdOrders = DB::transaction(function () use ($baseOrderData, $instockItems, $preorderItems, $user) {
            $orders = [];

            // Заказ (instock)
            if (! empty($instockItems)) {
                $order = Order::create(array_merge($baseOrderData, [
                    'type' => OrderType::ORDER,
                ]));
                $total = $this->createOrderItems($order, $instockItems, $user);
                $order->update(['total_amount' => $total]);
                $orders[] = $order;
            }

            // Предзаказ (preorder)
            if (! empty($preorderItems)) {
                $order = Order::create(array_merge($baseOrderData, [
                    'type' => OrderType::PREORDER,
                ]));
                $total = $this->createOrderItems($order, $preorderItems, $user);
                $order->update(['total_amount' => $total]);
                $orders[] = $order;
            }

            return $orders;
        });

        // Dispatch events после коммита
        foreach ($createdOrders as $order) {
            OrderCreated::dispatch($order->fresh());
        }

        // Формируем ответ
        $responseOrders = array_map(fn (Order $order) => [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'type' => $order->type?->value ?? 'order',
            'delivery_method' => $order->delivery_method?->value ?? DeliveryMethod::DELIVERY->value,
            'total_amount' => round((float) $order->total_amount, 2),
            'items_count' => $order->items()->count(),
            'status' => $order->status?->value ?? OrderStatus::PENDING_APPROVAL->value,
        ], $createdOrders);

        $response = [
            'orders' => $responseOrders,
            'total_orders' => count($createdOrders),
            'fully_fulfilled' => empty($unavailable) && empty($partial),
        ];

        if (! empty($unavailable) || ! empty($partial)) {
            $response['warnings'] = [
                'message' => 'Заказ принят. Часть позиций недоступна или отгружена не в полном объёме.',
                'unavailable' => $unavailable,
                'partial' => $partial,
            ];
        }

        return response()->json($response, 201);
    }

    /**
     * Собрать текстовую пометку о недоступных/частичных позициях для комментария заказа.
     * Возвращает null, если заказ выполнен полностью.
     *
     * @param  array<int, array<string, mixed>>  $unavailable
     * @param  array<int, array<string, mixed>>  $partial
     */
    protected function buildFulfillmentNote(array $unavailable, array $partial): ?string
    {
        if (empty($unavailable) && empty($partial)) {
            return null;
        }

        $lines = ['[API] Заказ принят не в полном объёме:'];

        foreach ($unavailable as $u) {
            $label = $u['name'] ?? $u['identifier'];
            $lines[] = "— «{$label}» (запрошено {$u['requested']}): {$u['message']}";
        }

        foreach ($partial as $p) {
            $lines[] = "— «{$p['name']}»: запрошено {$p['requested']}, отгружено {$p['fulfilled']}, не хватило {$p['shortfall']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Создание позиций заказа.
     */
    protected function createOrderItems(Order $order, array $items, $user): float
    {
        $total = 0;

        foreach ($items as $item) {
            $product = $item['product'];
            $quantity = $item['quantity'];

            $priceResult = $this->priceService->getPriceResult($product, $user);
            $displayPrice = $priceResult->getDisplayPrice();
            $subtotal = $displayPrice * $quantity;
            $total += $subtotal;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => $displayPrice,
                'base_price' => $priceResult->basePrice,
                'discount_percent' => $priceResult->discountPercent,
                'final_price' => $displayPrice,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
            ]);
        }

        return $total;
    }

    /**
     * Найти и валидировать API-токен.
     */
    protected function resolveToken(string $token): ApiToken
    {
        $apiToken = ApiToken::where('token', $token)
            ->where('is_active', true)
            ->first();

        abort_if(! $apiToken, 404, 'API-ключ не найден или деактивирован.');

        // Обновить last_used_at, не чаще 1 раза в минуту
        if (! $apiToken->last_used_at || $apiToken->last_used_at->diffInMinutes(now()) >= 1) {
            $apiToken->touchLastUsed();
        }

        return $apiToken;
    }

    /**
     * Найти товар по идентификатору (uuid, code, sku, barcode).
     */
    protected function resolveProduct(string $identifier): ?Product
    {
        // UUID формат: 8-4-4-4-12 hex chars
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            $product = Product::where('external_id', $identifier)->first();
            if ($product) {
                return $product;
            }
        }

        // Попробовать по code, sku, barcode — в этом порядке
        return Product::where('code', $identifier)->first()
            ?? Product::where('sku', $identifier)->first()
            ?? Product::where('barcode', $identifier)->first();
    }
}

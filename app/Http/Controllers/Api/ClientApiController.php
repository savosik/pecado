<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
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
        if (!$currency) {
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
            if ($currency && !$currency->is_base) {
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
            'comment' => 'nullable|string|max:1000',
            'products' => 'required|array|min:1',
            'products.*.identifier' => 'required|string|max:255',
            'products.*.quantity' => 'required|integer|min:1',
        ], [
            'inn.required' => 'ИНН обязателен',
            'products.required' => 'Список товаров обязателен',
            'products.min' => 'Список товаров не может быть пустым',
            'products.*.identifier.required' => 'Идентификатор товара обязателен',
            'products.*.quantity.required' => 'Количество обязательно',
            'products.*.quantity.min' => 'Количество должно быть не менее 1',
        ]);

        // Найти компанию по ИНН
        $company = $user->companies()->where('tax_id', $validated['inn'])->first();
        if (!$company) {
            return response()->json([
                'error' => 'Компания с указанным ИНН не найдена в вашем аккаунте',
                'inn' => $validated['inn'],
            ], 422);
        }



        // Резолвить товары и проверить остатки
        $instockItems = [];
        $preorderItems = [];
        $errors = [];
        $insufficientStock = [];

        foreach ($validated['products'] as $index => $item) {
            $product = $this->resolveProduct($item['identifier']);
            if (!$product) {
                $errors[] = "Товар \"{$item['identifier']}\" не найден (позиция " . ($index + 1) . ')';
                continue;
            }

            $stock = $this->stockService->getStock($product, $user);
            $totalAvailable = $stock['available'] + $stock['preorder'];
            $requestedQty = $item['quantity'];

            // Проверка: хватает ли остатков
            if ($requestedQty > $totalAvailable) {
                $insufficientStock[] = [
                    'identifier' => $item['identifier'],
                    'name' => $product->name,
                    'requested' => $requestedQty,
                    'available' => $totalAvailable,
                ];
                continue;
            }

            // Разделение на instock и preorder (как в CartService)
            $instockQty = min($requestedQty, $stock['available']);
            $preorderQty = $requestedQty - $instockQty;

            if ($instockQty > 0) {
                $instockItems[] = ['product' => $product, 'quantity' => $instockQty];
            }
            if ($preorderQty > 0) {
                $preorderItems[] = ['product' => $product, 'quantity' => $preorderQty];
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'error' => 'Некоторые товары не найдены',
                'details' => $errors,
            ], 422);
        }

        if (!empty($insufficientStock)) {
            return response()->json([
                'error' => 'Недостаточно остатков для некоторых товаров',
                'details' => $insufficientStock,
            ], 422);
        }

        // Валюта пользователя (как в CheckoutService)
        $currency = $this->currencyResolver->resolve($user);

        $baseOrderData = [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'delivery_address' => $validated['address'] ?? null,
            'status' => OrderStatus::PENDING,
            'comment' => $validated['comment'] ?? null,
            'total_amount' => 0,
            'exchange_rate' => $currency?->exchange_rate ?? 1.0,
            'rate_coefficient' => $currency?->rate_coefficient ?? 1.0,
            'currency_code' => $currency?->code ?? 'RUB',
        ];

        // Создать заказ(ы) в транзакции
        $createdOrders = DB::transaction(function () use ($baseOrderData, $instockItems, $preorderItems, $user) {
            $orders = [];

            // Заказ (instock)
            if (!empty($instockItems)) {
                $order = Order::create(array_merge($baseOrderData, [
                    'type' => OrderType::ORDER,
                ]));
                $total = $this->createOrderItems($order, $instockItems, $user);
                $order->update(['total_amount' => $total]);
                $orders[] = $order;
            }

            // Предзаказ (preorder)
            if (!empty($preorderItems)) {
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
        $responseOrders = array_map(fn(Order $order) => [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'type' => $order->type?->value ?? 'order',
            'total_amount' => round((float) $order->total_amount, 2),
            'items_count' => $order->items()->count(),
            'status' => 'pending',
        ], $createdOrders);

        return response()->json([
            'orders' => $responseOrders,
            'total_orders' => count($createdOrders),
        ], 201);
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

        abort_if(!$apiToken, 404, 'API-ключ не найден или деактивирован.');

        // Обновить last_used_at, не чаще 1 раза в минуту
        if (!$apiToken->last_used_at || $apiToken->last_used_at->diffInMinutes(now()) >= 1) {
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

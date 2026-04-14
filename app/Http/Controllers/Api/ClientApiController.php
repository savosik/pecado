<?php

namespace App\Http\Controllers\Api;

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
        protected StockServiceInterface $stockService
    ) {}

    /**
     * GET /api/client-api/{token}/prices
     * Получить цены пользователя.
     */
    public function prices(Request $request, string $token): JsonResponse
    {
        $apiToken = $this->resolveToken($token);
        $user = $apiToken->user;

        $perPage = min((int) $request->input('per_page', 500), 1000);

        $products = Product::query()
            ->select('id', 'external_id', 'code', 'sku', 'barcode', 'base_price', 'name')
            ->orderBy('id')
            ->paginate($perPage);

        $data = $products->getCollection()->map(function (Product $product) use ($user) {
            $priceResult = $this->priceService->getPriceResult($product, $user);

            return [
                'uuid' => $product->external_id,
                'code' => $product->code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'base_price' => round($priceResult->basePrice, 2),
                'price' => round($priceResult->getDisplayPrice(), 2),
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
     * Создать заказ.
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

        // Найти адрес доставки (если указан)
        $deliveryAddress = null;
        if (!empty($validated['address'])) {
            $deliveryAddress = $user->deliveryAddresses()
                ->where('address', $validated['address'])
                ->first();

            // Если адрес не найден — создать новый
            if (!$deliveryAddress) {
                $deliveryAddress = $user->deliveryAddresses()->create([
                    'name' => 'API',
                    'address' => $validated['address'],
                ]);
            }
        }

        // Резолвить товары
        $resolvedProducts = [];
        $errors = [];

        foreach ($validated['products'] as $index => $item) {
            $product = $this->resolveProduct($item['identifier']);
            if (!$product) {
                $errors[] = "Товар \"{$item['identifier']}\" не найден (позиция " . ($index + 1) . ')';
                continue;
            }
            $resolvedProducts[] = [
                'product' => $product,
                'quantity' => $item['quantity'],
            ];
        }

        if (!empty($errors)) {
            return response()->json([
                'error' => 'Некоторые товары не найдены',
                'details' => $errors,
            ], 422);
        }

        // Создать заказ
        $order = DB::transaction(function () use ($user, $company, $deliveryAddress, $validated, $resolvedProducts) {
            $order = Order::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'delivery_address_id' => $deliveryAddress?->id,
                'status' => OrderStatus::PENDING,
                'type' => OrderType::ORDER,
                'comment' => $validated['comment'] ?? null,
                'total_amount' => 0,
                'currency_code' => 'RUB',
            ]);

            $totalAmount = 0;

            foreach ($resolvedProducts as $item) {
                $product = $item['product'];
                $quantity = $item['quantity'];

                $priceResult = $this->priceService->getPriceResult($product, $user);
                $displayPrice = $priceResult->getDisplayPrice();
                $subtotal = $displayPrice * $quantity;
                $totalAmount += $subtotal;

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

            $order->update(['total_amount' => $totalAmount]);

            return $order;
        });

        // Dispatch event после коммита
        OrderCreated::dispatch($order->fresh());

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->number ?? ('#' . $order->id),
            'total_amount' => round((float) $order->total_amount, 2),
            'items_count' => count($resolvedProducts),
            'status' => 'pending',
        ], 201);
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

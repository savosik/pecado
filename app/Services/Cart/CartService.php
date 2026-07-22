<?php

namespace App\Services\Cart;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Defect\DefectStockServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CartService implements CartServiceInterface
{
    public function __construct(
        protected PriceServiceInterface $priceService,
        protected StockServiceInterface $stockService,
        protected DefectStockServiceInterface $defectStockService
    ) {}

    /**
     * Get summary information for a cart.
     *
     * @return array{total_price: float, items_count: int, available_count: int, preorder_count: int}
     */
    public function getCartSummary(Cart $cart, User $user): array
    {
        $agg = $this->computeCartAggregates($cart, $user);

        return [
            'total_price' => $agg['total_amount_discounted'],
            'items_count' => $agg['total_quantity'],
            'available_count' => $agg['available_count'],
            'preorder_count' => $agg['preorder_count'],
        ];
    }

    /**
     * Get summary information for multiple carts.
     *
     * @param  Collection<int, Cart>  $carts
     * @return array<int, array{total_price: float, items_count: int, available_count: int, preorder_count: int}>
     */
    public function getCartsSummary(Collection $carts, User $user): array
    {
        $summaries = [];

        foreach ($carts as $cart) {
            $summaries[$cart->id] = $this->getCartSummary($cart, $user);
        }

        return $summaries;
    }

    public function moveItems(Cart $sourceCart, Cart $targetCart, array $itemIds = []): void
    {
        $itemsQuery = $sourceCart->items();

        if (! empty($itemIds)) {
            $itemsQuery->whereIn('product_id', $itemIds);
        }

        $itemsToMove = $itemsQuery->get();

        foreach ($itemsToMove as $item) {
            $existingItem = $targetCart->items()
                ->where('product_id', $item->product_id)
                ->where('item_type', $item->item_type)
                ->where('warehouse_id', $item->warehouse_id)
                // Уценка — разные партии одного товара нельзя сливать в одну строку.
                ->where('product_defect_id', $item->product_defect_id)
                ->first();

            if ($existingItem) {
                $existingItem->update([
                    'quantity' => $existingItem->quantity + $item->quantity,
                ]);
                $item->delete();
            } else {
                $item->update([
                    'cart_id' => $targetCart->id,
                ]);
            }
        }
    }

    /**
     * Get or create the active cart for a user.
     */
    public function getOrCreateActiveCart(User $user): Cart
    {
        $cart = $user->carts()->active()->first();

        if (! $cart) {
            $cart = $user->carts()->create([
                'name' => 'Корзина',
                'is_active' => true,
            ]);
        }

        return $cart;
    }

    /**
     * Add a product to the cart.
     * Calculates current quantity + qty and delegates to setProductQuantity.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function addProduct(User $user, Cart $cart, Product $product, int $qty): array
    {
        // Уценку не учитываем: её количество считается по партии, не по товару.
        $currentQty = $cart->items()
            ->where('product_id', $product->id)
            ->excludingDefect()
            ->sum('quantity');

        $newTotalQty = $currentQty + $qty;

        return $this->setProductQuantity($user, $cart, $product, $newTotalQty);
    }

    /**
     * Update cart item quantity with spillover logic.
     *
     * Обновляет переданную строку in-place (не пересоздавая её), чтобы фронт
     * мог продолжать использовать тот же $item->id для последующих PATCH-ов.
     * Соседняя строка того же товара (instock vs preorder) тоже обновляется
     * in-place; новая создаётся только если её действительно нет.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function updateItemQuantity(User $user, CartItem $item, int $qty): array
    {
        if ($item->cart->user_id !== $user->id) {
            throw new \LogicException('Доступ запрещён.');
        }

        // Уценка живёт по своим правилам (цена и лимит — от партии), spillover
        // instock/preorder к ней неприменим. Меняют её через setDefectQuantity.
        if ($item->isDefect()) {
            throw new \LogicException('Количество уценки меняется через отдельный метод.');
        }

        $cart = $item->cart;
        $product = $item->product;

        $stock = $this->stockService->getStock($product, $user);
        $stockAvailable = (int) ($stock['available'] ?? 0);
        $stockPreorder = (int) ($stock['preorder'] ?? 0);
        $maxTotal = $stockAvailable + $stockPreorder;

        $otherItem = $cart->items()
            ->where('product_id', $product->id)
            ->where('id', '!=', $item->id)
            ->excludingDefect()
            ->first();
        $otherQty = (int) ($otherItem?->quantity ?? 0);

        $totalQty = $otherQty + max(0, $qty);
        $clamped = max(0, min($totalQty, $maxTotal));

        $instockTotal = min($clamped, $stockAvailable);
        $preorderTotal = $clamped - $instockTotal;

        $itemType = $item->item_type === 'preorder' ? 'preorder' : 'instock';
        $otherType = $itemType === 'instock' ? 'preorder' : 'instock';

        $itemNewQty = $itemType === 'instock' ? $instockTotal : $preorderTotal;
        $otherNewQty = $otherType === 'instock' ? $instockTotal : $preorderTotal;

        $price = $this->priceService->getUserPrice($product, $user);

        DB::transaction(function () use ($cart, $item, $otherItem, $product, $itemNewQty, $otherNewQty, $otherType, $price) {
            $cart->items()->where('product_id', $product->id)->lockForUpdate()->get();

            if ($itemNewQty > 0) {
                $item->update(['quantity' => $itemNewQty, 'price' => $price]);
            } else {
                $item->delete();
            }

            if ($otherItem) {
                if ($otherNewQty > 0) {
                    $otherItem->update(['quantity' => $otherNewQty, 'price' => $price]);
                } else {
                    $otherItem->delete();
                }
            } elseif ($otherNewQty > 0) {
                $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $otherNewQty,
                    'price' => $price,
                    'item_type' => $otherType,
                    'warehouse_id' => null,
                ]);
            }
        }, 3);

        $cart->load('items.product');

        return [
            'instock' => $instockTotal,
            'preorder' => $preorderTotal,
            'clamped' => $clamped,
            'max_total' => $maxTotal,
            'cart_totals' => $this->buildCartTotals($cart, $user),
        ];
    }

    /**
     * Remove a cart item.
     */
    public function removeItem(User $user, CartItem $item): void
    {
        if ($item->cart->user_id !== $user->id) {
            throw new \LogicException('Доступ запрещён.');
        }

        $item->delete();
    }

    /**
     * Set exact product quantity with spillover logic.
     * Main API method for frontend.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function setProductQuantity(User $user, Cart $cart, Product $product, int $qty): array
    {
        $stock = $this->stockService->getStock($product, $user);
        $maxTotal = $stock['available'] + $stock['preorder'];

        // If qty is 0, remove all items for this product (кроме уценки —
        // у неё отдельная строка на партию, её удаляют через setDefectQuantity)
        if ($qty <= 0) {
            $cart->items()->where('product_id', $product->id)->excludingDefect()->delete();

            return [
                'instock' => 0,
                'preorder' => 0,
                'clamped' => 0,
                'max_total' => $maxTotal,
                'cart_totals' => $this->buildCartTotals($cart, $user),
            ];
        }

        $clamped = min($qty, $maxTotal);
        $instockQty = min($clamped, $stock['available']);
        $preorderQty = $clamped - $instockQty;

        $price = $this->priceService->getUserPrice($product, $user);

        DB::transaction(function () use ($cart, $product, $instockQty, $preorderQty, $price) {
            // Lock existing rows to prevent race conditions (уценку не трогаем)
            $cart->items()->where('product_id', $product->id)->excludingDefect()->lockForUpdate()->get();
            $cart->items()->where('product_id', $product->id)->excludingDefect()->delete();

            if ($instockQty > 0) {
                $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $instockQty,
                    'price' => $price,
                    'item_type' => 'instock',
                    'warehouse_id' => null,
                ]);
            }

            if ($preorderQty > 0) {
                $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $preorderQty,
                    'price' => $price,
                    'item_type' => 'preorder',
                    'warehouse_id' => null,
                ]);
            }
        }, 3);

        // Refresh to get updated totals (eager-load product for buildCartTotals)
        $cart->load('items.product');

        return [
            'instock' => $instockQty,
            'preorder' => $preorderQty,
            'clamped' => $clamped,
            'max_total' => $maxTotal,
            'cart_totals' => $this->buildCartTotals($cart, $user),
        ];
    }

    /**
     * Bulk-вариант setProductQuantity. Считает spillover для каждого товара,
     * затем удаляет/создаёт строки cart_items в одной транзакции, чтобы
     * параллельные запросы не дедлочились на одной корзине.
     *
     * @param  array<int,int>  $quantities  карта product_id → qty
     */
    public function setProductsQuantity(User $user, Cart $cart, array $quantities): array
    {
        if (empty($quantities)) {
            return [
                'items' => [],
                'cart_totals' => $this->buildCartTotals($cart, $user),
            ];
        }

        $productIds = array_map('intval', array_keys($quantities));
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        // Считаем spillover и цену для каждого товара заранее, до транзакции:
        // priceService и stockService могут ходить во внешние ресурсы
        // (отдельная БД individual_prices, кэш стоков), не нужно держать их
        // под блокировкой строк cart_items.
        $plans = [];
        foreach ($quantities as $pid => $qty) {
            $pid = (int) $pid;
            $product = $products->get($pid);
            if (! $product) {
                continue;
            }

            $qty = max(0, (int) $qty);
            $stock = $this->stockService->getStock($product, $user);
            $maxTotal = (int) $stock['available'] + (int) $stock['preorder'];
            $clamped = min($qty, $maxTotal);
            $instockQty = min($clamped, (int) $stock['available']);
            $preorderQty = $clamped - $instockQty;
            $price = $this->priceService->getUserPrice($product, $user);

            $plans[$pid] = [
                'product' => $product,
                'price' => $price,
                'instock' => $instockQty,
                'preorder' => $preorderQty,
                'clamped' => $clamped,
                'max_total' => $maxTotal,
            ];
        }

        if (! empty($plans)) {
            DB::transaction(function () use ($cart, $plans) {
                $pids = array_keys($plans);
                // Берём блокировки на все затрагиваемые строки одним запросом —
                // в детерминированном порядке, чтобы конкурентные транзакции
                // ждали друг друга, а не пересекались по разным product_id.
                $cart->items()
                    ->whereIn('product_id', $pids)
                    ->excludingDefect()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $cart->items()->whereIn('product_id', $pids)->excludingDefect()->delete();

                $rows = [];
                $now = now();
                foreach ($plans as $pid => $plan) {
                    if ($plan['instock'] > 0) {
                        $rows[] = [
                            'cart_id' => $cart->id,
                            'product_id' => $pid,
                            'quantity' => $plan['instock'],
                            'price' => $plan['price'],
                            'item_type' => 'instock',
                            'warehouse_id' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($plan['preorder'] > 0) {
                        $rows[] = [
                            'cart_id' => $cart->id,
                            'product_id' => $pid,
                            'quantity' => $plan['preorder'],
                            'price' => $plan['price'],
                            'item_type' => 'preorder',
                            'warehouse_id' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if (! empty($rows)) {
                    CartItem::insert($rows);
                }
            }, 3);
        }

        $cart->load('items.product');

        $items = [];
        foreach ($plans as $pid => $plan) {
            $items[$pid] = [
                'product_id' => $pid,
                'instock' => $plan['instock'],
                'preorder' => $plan['preorder'],
                'clamped' => $plan['clamped'],
                'max_total' => $plan['max_total'],
            ];
        }

        return [
            'items' => $items,
            'cart_totals' => $this->buildCartTotals($cart, $user),
        ];
    }

    /**
     * Добавить уценённую партию в корзину (текущее количество + qty).
     *
     * @return array{quantity: int, available: int, cart_totals: array}
     */
    public function addDefect(User $user, Cart $cart, ProductDefect $defect, int $qty): array
    {
        $current = (int) $cart->items()
            ->where('product_defect_id', $defect->id)
            ->sum('quantity');

        return $this->setDefectQuantity($user, $cart, $defect, $current + $qty);
    }

    /**
     * Задать точное количество уценённой партии в корзине.
     *
     * Уценка автономна: отдельная строка на партию, фиксированная цена из партии
     * (индивидуальные цены и скидки к ней не применяются), лимит — свободный
     * остаток партии. Резерв возникает только при оформлении заказа, поэтому
     * здесь ограничиваемся текущим available; финальную гонку разрешает checkout.
     *
     * @return array{quantity: int, available: int, cart_totals: array}
     */
    public function setDefectQuantity(User $user, Cart $cart, ProductDefect $defect, int $qty): array
    {
        // Партия должна быть допущена в продажу; закрытую/снятую с публикации —
        // молча приравниваем к недоступной (0), чтобы устаревшая вкладка не добавляла.
        $available = $defect->loadMissing('product')->price !== null
            && $defect->is_published
            && ! $defect->isClosed()
            ? $this->defectStockService->available($defect)
            : 0;

        $clamped = max(0, min($qty, $available));

        DB::transaction(function () use ($cart, $defect, $clamped) {
            $cart->items()->where('product_defect_id', $defect->id)->lockForUpdate()->get();
            $cart->items()->where('product_defect_id', $defect->id)->delete();

            if ($clamped > 0) {
                $cart->items()->create([
                    'product_id' => $defect->product_id,
                    'product_defect_id' => $defect->id,
                    'quantity' => $clamped,
                    'price' => $defect->price,
                    'item_type' => 'defect',
                    'warehouse_id' => null,
                ]);
            }
        }, 3);

        $cart->load('items.product');

        return [
            'quantity' => $clamped,
            'available' => $available,
            'cart_totals' => $this->buildCartTotals($cart, $user),
        ];
    }

    /**
     * Create a new cart for a user (deactivates other carts).
     */
    public function createCart(User $user, ?string $name = null): Cart
    {
        $user->carts()->update(['is_active' => false]);

        return $user->carts()->create([
            'name' => $name ?: 'Корзина',
            'is_active' => true,
        ]);
    }

    /**
     * Switch the active cart for a user.
     */
    public function switchActiveCart(User $user, Cart $cart): void
    {
        $user->carts()->update(['is_active' => false]);
        $cart->update(['is_active' => true]);
    }

    /**
     * Rename a cart.
     */
    public function renameCart(User $user, Cart $cart, string $name): void
    {
        $cart->update(['name' => $name]);
    }

    /**
     * Delete a cart. Cannot delete the last cart.
     * If the deleted cart was active, the next one becomes active.
     */
    public function deleteCart(User $user, Cart $cart): void
    {
        $cartsCount = $user->carts()->count();

        if ($cartsCount <= 1) {
            throw new \LogicException('Нельзя удалить последнюю корзину.');
        }

        $wasActive = $cart->is_active;
        $cart->items()->delete();
        $cart->delete();

        if ($wasActive) {
            $nextCart = $user->carts()->orderBy('id')->first();
            if ($nextCart) {
                $nextCart->update(['is_active' => true]);
            }
        }
    }

    /**
     * Get active cart quantities map: {productId: totalQty}.
     *
     * @return array<int, int>
     */
    public function getActiveQuantities(User $user): array
    {
        $cart = $this->getOrCreateActiveCart($user);

        return $cart->items()
            ->selectRaw('product_id, SUM(quantity) as total_qty')
            ->groupBy('product_id')
            ->pluck('total_qty', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->toArray();
    }

    /**
     * Active cart snapshot: quantities map + per-product split (instock/preorder)
     * + agregat totals (для бейджей корзины и цветной рамки counter).
     *
     * @return array{quantities: array<int,int>, splits: array<int, array{instock:int, preorder:int}>, totals: array}
     */
    public function getActiveCartSnapshot(User $user): array
    {
        $cart = $this->getOrCreateActiveCart($user);

        $quantities = [];
        $splits = [];
        foreach ($cart->items()->select('product_id', 'item_type', 'quantity')->get() as $item) {
            $pid = (int) $item->product_id;
            $qty = (int) $item->quantity;
            $quantities[$pid] = ($quantities[$pid] ?? 0) + $qty;
            $splits[$pid] = $splits[$pid] ?? ['instock' => 0, 'preorder' => 0];
            if ($item->item_type === 'instock') {
                $splits[$pid]['instock'] += $qty;
            } elseif ($item->item_type === 'preorder') {
                $splits[$pid]['preorder'] += $qty;
            }
        }

        return [
            'quantities' => $quantities,
            'splits' => $splits,
            'totals' => $this->buildCartTotals($cart, $user),
        ];
    }

    /**
     * Quantities of a single product across all of the user's carts.
     *
     * Используется мульти-корзинным контролом на странице товара и в quick
     * view: показывает, сколько данного товара лежит в каждой корзине, чтобы
     * пользователь мог сразу набросать его в несколько корзин.
     *
     * @return array{
     *     max_total: int,
     *     available: int,
     *     preorder: int,
     *     carts: array<int, array{id:int, name:string, is_active:bool, quantity:int, instock:int, preorder:int}>
     * }
     */
    public function getProductQuantitiesAcrossCarts(User $user, Product $product): array
    {
        $stock = $this->stockService->getStock($product, $user);
        $available = (int) $stock['available'];
        $preorderStock = (int) $stock['preorder'];

        $carts = $user->carts()->orderBy('id')->get();

        // Строки данного товара по всем корзинам пользователя — одним запросом.
        $itemsByCart = CartItem::query()
            ->whereIn('cart_id', $carts->pluck('id'))
            ->where('product_id', $product->id)
            ->select('cart_id', 'item_type', 'quantity')
            ->get()
            ->groupBy('cart_id');

        $cartsPayload = $carts->map(function (Cart $cart) use ($itemsByCart) {
            $rows = $itemsByCart->get($cart->id, collect());
            $instock = (int) $rows->where('item_type', 'instock')->sum('quantity');
            $preorder = (int) $rows->where('item_type', 'preorder')->sum('quantity');

            return [
                'id' => $cart->id,
                'name' => $cart->name,
                'is_active' => (bool) $cart->is_active,
                'quantity' => $instock + $preorder,
                'instock' => $instock,
                'preorder' => $preorder,
            ];
        })->values()->all();

        return [
            'max_total' => $available + $preorderStock,
            'available' => $available,
            'preorder' => $preorderStock,
            'carts' => $cartsPayload,
        ];
    }

    /**
     * Get cart items summary for API.
     */
    public function getCartItemsSummary(Cart $cart): array
    {
        $items = $cart->items()
            ->select('id', 'product_id', 'quantity', 'item_type')
            ->get();

        return [
            'items' => $items,
            'total_quantity' => $items->sum('quantity'),
        ];
    }

    /**
     * Get detailed cart data for Inertia page.
     */
    public function getCartDetails(Cart $cart, User $user): array
    {
        $cart->loadMissing(
            'items.product.brand',
            'items.product.barcodes',
            'items.product.media',
            'items.productDefect.media',
        );

        $items = $cart->items->map(function (CartItem $item) use ($user) {
            $product = $item->product;

            if (! $product) {
                return null;
            }

            if ($item->isDefect()) {
                return $this->defectItemDetails($item, $product);
            }

            $basePrice = $this->priceService->getBasePriceForUser($product, $user);
            $userPrice = $this->priceService->getUserPrice($product, $user);
            $stock = $this->stockService->getStock($product, $user);

            return [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'product' => [
                    'id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'code' => $product->code,
                    'thumbnail_url' => $product->getFirstMediaUrl('main', 'thumb') ?: null,
                    'main_image_url' => $product->getFirstMediaUrl('main') ?: null,
                    'brand' => $product->brand ? [
                        'id' => $product->brand->id,
                        'name' => $product->brand->name,
                        'slug' => $product->brand->slug,
                    ] : null,
                    'barcodes' => $product->barcodes->pluck('barcode')->toArray(),
                ],
                'price' => $item->price ?? 0,
                'price_regular' => $basePrice,
                'price_discounted' => $userPrice,
                'item_type' => $item->item_type,
                'is_unavailable' => ($stock['available'] + $stock['preorder']) <= 0,
                'available_quantity' => $stock['available'],
                'preorder_quantity' => $stock['preorder'],
                'max_total' => $stock['available'] + $stock['preorder'],
                'stock_status' => match (true) {
                    ($stock['available'] + $stock['preorder']) <= 0 => 'unavailable',
                    $item->quantity > ($stock['available'] + $stock['preorder']) => 'partial',
                    default => 'ok',
                },
                'total_amount' => round($item->quantity * ($item->price ?? 0), 2),
                'total_amount_regular' => round($item->quantity * $basePrice, 2),
                'total_amount_discounted' => round($item->quantity * $userPrice, 2),
            ];
        })->filter()->values()->toArray();

        return [
            'items' => $items,
            'total_quantity' => $cart->total_quantity,
            'instock_quantity' => $cart->instock_quantity,
            'preorder_quantity' => $cart->preorder_quantity,
            'total_amount_regular' => array_sum(array_column($items, 'total_amount_regular')),
            'total_amount_discounted' => array_sum(array_column($items, 'total_amount_discounted')),
        ];
    }

    // ────────────────────────────────────────────
    // Private
    // ────────────────────────────────────────────

    /**
     * Данные строки корзины для позиции уценки.
     *
     * Форма совместима с обычной строкой (те же ключи), но цена берётся из партии,
     * а лимит — свободный остаток партии + уже лежащее в этой строке (иначе своя же
     * позиция «съела» бы весь остаток и потолок оказался бы ниже текущего qty).
     */
    private function defectItemDetails(CartItem $item, Product $product): array
    {
        $defect = $item->productDefect;
        $sellable = $defect && $defect->is_published && $defect->price !== null && ! $defect->isClosed();
        $available = ($defect && $sellable) ? $this->defectStockService->available($defect) : 0;
        $maxTotal = $available + ($sellable ? $item->quantity : 0);
        $price = (float) ($item->price ?? 0);

        return [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'product' => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'sku' => $product->sku,
                'code' => $product->code,
                'thumbnail_url' => $product->getFirstMediaUrl('main', 'thumb') ?: null,
                'main_image_url' => $product->getFirstMediaUrl('main') ?: null,
                'brand' => $product->brand ? [
                    'id' => $product->brand->id,
                    'name' => $product->brand->name,
                    'slug' => $product->brand->slug,
                ] : null,
                'barcodes' => $product->barcodes->pluck('barcode')->toArray(),
            ],
            'price' => $price,
            'price_regular' => $price,
            'price_discounted' => $price,
            'item_type' => 'defect',
            'defect' => $defect ? [
                'id' => $defect->id,
                'description' => $defect->defect_description,
                'photo_url' => $defect->getFirstMediaUrl(ProductDefect::MEDIA_COLLECTION, 'thumb')
                    ?: $defect->getFirstMediaUrl(ProductDefect::MEDIA_COLLECTION) ?: null,
            ] : null,
            'is_unavailable' => ! $sellable || $maxTotal <= 0,
            'available_quantity' => $available,
            'preorder_quantity' => 0,
            'max_total' => $maxTotal,
            'stock_status' => match (true) {
                ! $sellable || $maxTotal <= 0 => 'unavailable',
                $item->quantity > $maxTotal => 'partial',
                default => 'ok',
            },
            'total_amount' => round($item->quantity * $price, 2),
            'total_amount_regular' => round($item->quantity * $price, 2),
            'total_amount_discounted' => round($item->quantity * $price, 2),
        ];
    }

    /**
     * Compute all cart aggregates in a single pass.
     * Used by getCartSummary and buildCartTotals to avoid duplication.
     */
    private function computeCartAggregates(Cart $cart, User $user): array
    {
        $cart->loadMissing('items.product');

        $totalQuantity = 0;
        $availableCount = 0;
        $preorderCount = 0;
        $totalAmountRegular = 0.0;
        $totalAmountDiscounted = 0.0;

        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $qty = $item->quantity;
            $totalQuantity += $qty;

            if ($item->isInstock()) {
                $availableCount += $qty;
            } elseif ($item->isPreorder()) {
                $preorderCount += $qty;
            }
            // Уценку не относим ни к available, ни к preorder — это отдельный поток.

            if ($item->isDefect()) {
                // Цена уценки фиксирована в строке; скидки/индивидуальные цены к ней не применяются.
                $defectPrice = (float) ($item->price ?? 0);
                $totalAmountRegular += $qty * $defectPrice;
                $totalAmountDiscounted += $qty * $defectPrice;
            } else {
                $totalAmountRegular += $qty * $this->priceService->getBasePriceForUser($product, $user);
                $totalAmountDiscounted += $qty * $this->priceService->getUserPrice($product, $user);
            }
        }

        return [
            'total_quantity' => $totalQuantity,
            'available_count' => $availableCount,
            'preorder_count' => $preorderCount,
            'total_amount_regular' => round($totalAmountRegular, 2),
            'total_amount_discounted' => round($totalAmountDiscounted, 2),
        ];
    }

    /**
     * Build cart totals summary.
     */
    protected function buildCartTotals(Cart $cart, User $user): array
    {
        $agg = $this->computeCartAggregates($cart, $user);

        return [
            'total_quantity' => $agg['total_quantity'],
            'instock_quantity' => $agg['available_count'],
            'preorder_quantity' => $agg['preorder_count'],
            'total_amount_regular' => $agg['total_amount_regular'],
            'total_amount_discounted' => $agg['total_amount_discounted'],
        ];
    }
}

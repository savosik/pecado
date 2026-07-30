<?php

namespace App\Services\Order;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Defect\DefectStockServiceInterface;
use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Order;
use App\Services\Defect\DefectPickListFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckoutService implements CheckoutServiceInterface
{
    public function __construct(
        protected PriceServiceInterface $priceService,
        protected UserCurrencyResolverInterface $currencyResolver,
        protected StockServiceInterface $stockService,
        protected DefectStockServiceInterface $defectStockService,
        protected DefectPickListFormatter $defectPickListFormatter,
        protected OrderAssembler $assembler,
    ) {}

    /**
     * Create order(s) from a cart, splitting by stock availability.
     * Returns a Collection of created Orders (1 or 2).
     *
     * @return Collection<int, Order>
     */
    public function checkout(
        Cart $cart,
        Company $company,
        ?string $deliveryAddress,
        ?string $comment = null,
        ?string $managerComment = null,
        ?string $warehouseComment = null,
        DeliveryMethod $deliveryMethod = DeliveryMethod::DELIVERY
    ): Collection {
        return DB::transaction(function () use ($cart, $company, $deliveryAddress, $comment, $managerComment, $warehouseComment, $deliveryMethod) {
            $user = $cart->user;
            $currency = $this->currencyResolver->resolve($user);

            // Остатки проверяем до сборки: чекаут отказывает целиком, а не урезает
            // количества, как это делает клиентское API
            $insufficientStockItems = [];
            foreach ($cart->items as $item) {
                if ($item->item_type === 'defect') {
                    // Уценка: лимит — свободный остаток конкретной партии, не остаток товара.
                    $defect = $item->productDefect;
                    $available = ($defect && $defect->is_published && $defect->price !== null && ! $defect->isClosed())
                        ? $this->defectStockService->available($defect)
                        : 0;

                    if ($item->quantity > $available) {
                        $insufficientStockItems[] = [
                            'cart_item_id' => $item->id,
                            'product_id' => $item->product->id,
                            'product' => $item->product->name,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                            'item_type' => 'defect',
                            'requested' => $item->quantity,
                            'available' => $available,
                        ];
                    }

                    continue;
                }

                $stock = $this->stockService->getStock($item->product, $user);
                $totalAvailable = $stock['available'] + $stock['preorder'];

                if ($item->quantity > $totalAvailable) {
                    $insufficientStockItems[] = [
                        'cart_item_id' => $item->id,
                        'product_id' => $item->product->id,
                        'product' => $item->product->name,
                        'name' => $item->product->name,
                        'sku' => $item->product->sku,
                        'item_type' => $item->item_type,
                        'requested' => $item->quantity,
                        'available' => $totalAvailable,
                    ];
                }
            }

            if (! empty($insufficientStockItems)) {
                throw new \App\Exceptions\InsufficientStockException(
                    'Insufficient stock for some items',
                    $insufficientStockItems
                );
            }

            // Строки уже разложены по item_type ещё при добавлении в корзину
            $inStockCartItems = $cart->items->filter(fn ($item) => $item->item_type === 'instock')->values();
            $preorderCartItems = $cart->items->filter(fn ($item) => $item->item_type === 'preorder')->values();
            $defectCartItems = $cart->items->filter(fn ($item) => $item->item_type === 'defect')->values();

            $warehouseComments = [];

            // Кладовщик собирает по печатному документу 1С и в WMS заходит редко —
            // дописываем в комментарий склада конкретику по каждой партии брака
            // (артикул, id партии, дефекты, количество).
            if ($defectCartItems->isNotEmpty()) {
                $defectCartItems->load('product', 'productDefect');

                $warehouseComments[OrderType::DEFECT->value] = trim(
                    ($warehouseComment ? $warehouseComment."\n\n" : '')
                    .$this->defectPickListFormatter->format($defectCartItems)
                );
            }

            $draft = new OrderDraft(
                user: $user,
                company: $company,
                deliveryMethod: $deliveryMethod,
                groups: [
                    OrderType::ORDER->value => $this->linesFromCart($inStockCartItems),
                    OrderType::PREORDER->value => $this->linesFromCart($preorderCartItems),
                    OrderType::DEFECT->value => $this->defectLinesFromCart($defectCartItems),
                ],
                deliveryAddress: $deliveryAddress,
                comment: $comment,
                managerComment: $managerComment,
                warehouseComment: $warehouseComment,
                cartId: $cart->id,
                currency: $currency,
                warehouseComments: $warehouseComments,
            );

            // Заказ уценки отгружается со склада некондиции. Остаток партии проверен
            // выше в этой же транзакции. Параллельный checkout той же партии может
            // дать небольшой овербукинг — как и для обычных товаров, окончательный
            // остаток подтверждает 1С реализацией.
            return $this->assembler->assemble($draft);
        });
    }

    /**
     * Обычные строки корзины: цену считает сборщик по прайсу клиента.
     *
     * @param  Collection<int, CartItem>  $cartItems
     * @return list<OrderLine>
     */
    private function linesFromCart(Collection $cartItems): array
    {
        return $cartItems
            ->map(fn (CartItem $item) => new OrderLine($item->product, $item->quantity))
            ->all();
    }

    /**
     * Строки уценки: цена зафиксирована в корзине (= цена партии), скидки
     * и индивидуальные цены к ней не применяются.
     *
     * @param  Collection<int, CartItem>  $cartItems
     * @return list<OrderLine>
     */
    private function defectLinesFromCart(Collection $cartItems): array
    {
        return $cartItems
            ->map(fn (CartItem $item) => OrderLine::defect(
                product: $item->product,
                quantity: $item->quantity,
                price: (float) ($item->price ?? 0),
                productDefectId: $item->product_defect_id,
                defectDescription: $item->productDefect?->defect_description,
            ))
            ->all();
    }
}

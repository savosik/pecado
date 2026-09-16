<?php

namespace App\Services\Order;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Order\CheckoutServiceInterface;
use App\Enums\DeliveryMethod;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\DeliveryAddressBook;
use Illuminate\Support\Collection;

/**
 * Оформление корзины целиком — то, что делает кнопка «Оформить» в кабинете.
 *
 * Пред- и пост-шаги вокруг {@see CheckoutService}: снятие предзаказных строк
 * («только со склада» или предзаказы выключены), запоминание способа доставки,
 * сохранение адреса, очистка корзины. Единственная реализация для кабинета
 * и API v1 — иначе через полгода два оформления разошлись бы в правилах.
 *
 * @throws NothingToCheckoutException корзина пуста или остался только предзаказ
 * @throws \App\Exceptions\InsufficientStockException остатки изменились
 * @throws \App\Exceptions\DebtRestrictionException лестница долга
 */
class CabinetCheckout
{
    public function __construct(
        private readonly CartServiceInterface $carts,
        private readonly CheckoutServiceInterface $checkout,
        private readonly DeliveryAddressBook $addresses,
    ) {}

    /**
     * @return Collection<int, Order>
     */
    public function submit(User $user, Cart $cart, Company $company, CheckoutRequestDto $request): Collection
    {
        if ($cart->items()->count() === 0) {
            throw NothingToCheckoutException::emptyCart();
        }

        // «Только со склада»: клиент не хочет ждать поставку — предзаказные строки
        // уходят из корзины до оформления. Если кроме них ничего нет, оформлять нечего.
        if ($request->instockOnly || ! $user->preordersEnabled()) {
            if ($cart->items()->where('item_type', '!=', 'preorder')->doesntExist()) {
                throw NothingToCheckoutException::preorderOnly();
            }

            $this->carts->removePreorderItems($user, $cart);
            $cart->load('items');
        }

        $orders = $this->checkout->checkout(
            $cart,
            $company,
            $request->deliveryAddress,
            $request->comment,
            $request->managerComment,
            $request->warehouseComment,
            $request->deliveryMethod,
            $request->reserve,
        );

        // Запомнить выбранный способ доставки для предвыбора на следующем оформлении.
        $user->update(['default_delivery_method' => $request->deliveryMethod]);

        if ($request->deliveryMethod === DeliveryMethod::DELIVERY && $request->saveAddress && $request->deliveryAddress) {
            $this->addresses->remember($user, $request->deliveryAddress, $request->addressName, $request->addressData, $request->addressMakeDefault);
        }

        $cart->items()->delete();

        return $orders;
    }
}

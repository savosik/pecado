<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreCheckoutRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CheckoutController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService,
        protected CheckoutServiceInterface $checkoutService,
        protected PriceServiceInterface $priceService,
        protected StockServiceInterface $stockService
    ) {}

    /**
     * Страница оформления заказа.
     * GET /checkout
     */
    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);

        // Если корзина пуста — перенаправляем в корзину
        if ($cart->items()->count() === 0) {
            return redirect()->route('cart.index');
        }

        $cartDetails = $this->cartService->getCartDetails($cart, $user);

        // Разделить товары на instock и preorder
        $items = $cartDetails['items'] ?? [];
        $instockItems = array_values(array_filter($items, fn ($it) => ($it['item_type'] ?? '') === 'instock'));
        $preorderItems = array_values(array_filter($items, fn ($it) => ($it['item_type'] ?? '') === 'preorder'));

        // Подытоги
        $instockTotals = [
            'quantity' => array_sum(array_column($instockItems, 'quantity')),
            'amount_regular' => array_sum(array_column($instockItems, 'total_amount_regular')),
            'amount_discounted' => array_sum(array_column($instockItems, 'total_amount_discounted')),
        ];
        $preorderTotals = [
            'quantity' => array_sum(array_column($preorderItems, 'quantity')),
            'amount_regular' => array_sum(array_column($preorderItems, 'total_amount_regular')),
            'amount_discounted' => array_sum(array_column($preorderItems, 'total_amount_discounted')),
        ];

        // Компании и адреса пользователя
        $companies = $user->companies()->select('id', 'name', 'legal_name', 'tax_id')->get();
        $addresses = $user->deliveryAddresses()->select('id', 'name', 'address')->get();

        return Inertia::render('User/Checkout/Index', [
            'cart' => [
                'id' => $cart->id,
                'name' => $cart->name,
            ],
            'instockItems' => $instockItems,
            'preorderItems' => $preorderItems,
            'instockTotals' => $instockTotals,
            'preorderTotals' => $preorderTotals,
            'grandTotal' => [
                'quantity' => $cartDetails['total_quantity'] ?? 0,
                'amount_regular' => $cartDetails['total_amount_regular'] ?? 0,
                'amount_discounted' => $cartDetails['total_amount_discounted'] ?? 0,
            ],
            'companies' => $companies,
            'addresses' => $addresses,
            'countries' => collect(Country::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
        ]);
    }

    /**
     * Оформить заказ.
     * POST /checkout
     */
    public function store(StoreCheckoutRequest $request): RedirectResponse
    {
        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);

        if ($cart->items()->count() === 0) {
            return redirect()->route('cart.index');
        }

        $company = $user->companies()->findOrFail($request->validated('company_id'));

        try {
            $orders = $this->checkoutService->checkout(
                $cart,
                $company,
                $request->validated('delivery_address'),
                $request->validated('comment')
            );

            // Очистить корзину после успешного заказа
            $cart->items()->delete();

            // Если создано два заказа (обычный + предзаказ) — редиректим в список заказов
            if ($orders->count() > 1) {
                return redirect()
                    ->route('cabinet.orders.index')
                    ->with('success', 'Заказы успешно оформлены!');
            }

            // Один заказ — редиректим на его страницу
            return redirect()
                ->route('cabinet.orders.show', $orders->first())
                ->with('success', 'Заказ успешно оформлен!');
        } catch (\App\Exceptions\InsufficientStockException $e) {
            return back()->withErrors([
                'stock' => 'Недостаточно товара на складе: '.collect($e->getItems())
                    ->pluck('product')
                    ->join(', '),
            ]);
        }
    }
}

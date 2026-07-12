<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\Country;
use App\Enums\DeliveryMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreCheckoutRequest;
use App\Models\DeliveryAddress;
use App\Models\User;
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
        $companies = $user->companies()->select('id', 'name', 'legal_name', 'tax_id', 'is_default')->get();
        $addresses = $user->deliveryAddresses()
            ->select('id', 'name', 'address', 'is_default')
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

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
            // Запомненный способ доставки из последнего заказа (иначе — доставка).
            'defaultDeliveryMethod' => ($user->default_delivery_method ?? DeliveryMethod::DELIVERY)->value,
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

        $deliveryMethod = DeliveryMethod::from($request->validated('delivery_method'));

        try {
            $orders = $this->checkoutService->checkout(
                $cart,
                $company,
                $request->validated('delivery_address'),
                $request->validated('comment'),
                $request->validated('manager_comment'),
                $request->validated('warehouse_comment'),
                $deliveryMethod
            );

            // Запомнить выбранный способ доставки для предвыбора на следующем checkout.
            $user->update(['default_delivery_method' => $deliveryMethod]);

            // Сохранить новый адрес в список пользователя (только при доставке и по запросу).
            if ($deliveryMethod === DeliveryMethod::DELIVERY && $request->boolean('save_address')) {
                $this->saveDeliveryAddress($user, $request);
            }

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
            return back()
                ->withErrors([
                    'stock' => 'Количество товаров на складе изменилось. Уточните корзину перед оформлением.',
                ])
                ->with('stock_conflicts', $e->getItems());
        }
    }

    /**
     * Сохранить введённый на checkout адрес в список адресов пользователя.
     * Дубли (точное совпадение строки адреса) не создаём.
     * При address_make_default делаем адрес адресом по умолчанию.
     */
    private function saveDeliveryAddress(User $user, StoreCheckoutRequest $request): void
    {
        $address = trim((string) $request->validated('delivery_address'));

        if ($address === '') {
            return;
        }

        $makeDefault = $request->boolean('address_make_default');

        $existing = $user->deliveryAddresses()->where('address', $address)->first();

        if ($existing) {
            // Дубликат: при необходимости лишь переназначаем «по умолчанию».
            if ($makeDefault && ! $existing->is_default) {
                DeliveryAddress::where('user_id', $user->id)->update(['is_default' => false]);
                $existing->update(['is_default' => true]);
            }

            return;
        }

        if ($makeDefault) {
            DeliveryAddress::where('user_id', $user->id)->update(['is_default' => false]);
        }

        $name = trim((string) $request->validated('address_name'));

        $user->deliveryAddresses()->create([
            'name' => $name !== '' ? $name : 'Адрес доставки',
            'address' => $address,
            'address_data' => $request->validated('address_data'),
            'is_default' => $makeDefault,
        ]);
    }

    /**
     * Привести количество в корзине к доступному остатку.
     * Для каждой позиции: если запрошено больше доступного — уменьшить до доступного;
     * если доступно 0 — удалить позицию из корзины.
     * После — редирект на /checkout, чтобы пользователь увидел актуальный состав.
     *
     * POST /checkout/normalize-stock
     */
    public function normalizeStock(Request $request): RedirectResponse
    {
        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);

        $adjusted = 0;
        $removed = 0;

        $cart->loadMissing('items.product');

        foreach ($cart->items as $item) {
            if (! $item->product) {
                continue;
            }

            $stock = $this->stockService->getStock($item->product, $user);
            $totalAvailable = (int) ($stock['available'] + $stock['preorder']);

            if ($item->quantity <= $totalAvailable) {
                continue;
            }

            if ($totalAvailable <= 0) {
                $item->delete();
                $removed++;

                continue;
            }

            $item->quantity = $totalAvailable;
            $item->save();
            $adjusted++;
        }

        if ($adjusted === 0 && $removed === 0) {
            return redirect()
                ->route('checkout.index')
                ->with('info', 'Корзина уже соответствует доступным остаткам.');
        }

        $parts = [];
        if ($adjusted > 0) {
            $parts[] = 'скорректировано позиций: '.$adjusted;
        }
        if ($removed > 0) {
            $parts[] = 'удалено: '.$removed;
        }

        // Если корзина опустела — увести в корзину
        if ($cart->items()->count() === 0) {
            return redirect()
                ->route('cart.index')
                ->with('warning', 'Корзина опустела после сверки с остатками ('.implode(', ', $parts).').');
        }

        return redirect()
            ->route('checkout.index')
            ->with('success', 'Корзина приведена к доступному ('.implode(', ', $parts).').');
    }
}

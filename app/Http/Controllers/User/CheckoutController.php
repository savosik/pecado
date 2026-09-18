<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Enums\Country;
use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreCheckoutRequest;
use App\Models\Order;
use App\Services\Cart\CartStockNormalizer;
use App\Services\Order\CabinetCheckout;
use App\Services\Order\CheckoutPreview;
use App\Services\Order\CheckoutRequestDto;
use App\Services\Order\NothingToCheckoutException;
use App\Support\Preorder\PreorderTerms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CheckoutController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService,
        protected CheckoutPreview $preview,
        protected CabinetCheckout $checkout,
        protected CartStockNormalizer $normalizer,
    ) {}

    /**
     * Страница оформления заказа.
     * GET /checkout
     */
    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);

        // Клиент выключил предзаказы уже после того, как строки легли в корзину
        // (другая вкладка, менеджер в CRM): убираем их молча, а не показываем
        // «остатки изменились» на товар, который он просил не предлагать.
        if (! $user->preordersEnabled()) {
            $this->cartService->removePreorderItems($user, $cart);
        }

        // Если корзина пуста — перенаправляем в корзину
        if ($cart->items()->count() === 0) {
            return redirect()->route('cart.index');
        }

        $preview = $this->preview->build($user, $cart);

        // Компании и адреса пользователя
        $companies = $user->companies()->select('id', 'name', 'legal_name', 'tax_id', 'is_default')->get();
        $addresses = $user->deliveryAddresses()
            ->select('id', 'name', 'address', 'is_default')
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('User/Checkout/Index', [
            'cart' => $preview['cart'],
            'instockItems' => $preview['instock_items'],
            'preorderItems' => $preview['preorder_items'],
            'defectItems' => $preview['defect_items'],
            'promoItems' => $preview['promo_items'],
            'sampleItems' => $preview['sample_items'],
            'instockTotals' => $preview['instock_totals'],
            'preorderTotals' => $preview['preorder_totals'],
            'defectTotals' => $preview['defect_totals'],
            'promoTotals' => $preview['promo_totals'],
            'sampleTotals' => $preview['sample_totals'],
            'grandTotal' => $preview['grand_total'],
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
            $orders = $this->checkout->submit($user, $cart, $company, new CheckoutRequestDto(
                deliveryMethod: $deliveryMethod,
                deliveryAddress: $request->validated('delivery_address'),
                comment: $request->validated('comment'),
                managerComment: $request->validated('manager_comment'),
                warehouseComment: $request->validated('warehouse_comment'),
                instockOnly: $request->boolean('instock_only'),
                reserve: $request->boolean('reserve'),
                saveAddress: $request->boolean('save_address'),
                addressName: $request->validated('address_name'),
                addressMakeDefault: $request->boolean('address_make_default'),
                addressData: $request->validated('address_data'),
            ));
        } catch (NothingToCheckoutException $e) {
            if ($e->reason === NothingToCheckoutException::EMPTY_CART) {
                return redirect()->route('cart.index');
            }

            return back()->withErrors(['stock' => $e->getMessage()]);
        } catch (\App\Exceptions\InsufficientStockException $e) {
            return back()
                ->withErrors([
                    'stock' => 'Количество товаров на складе изменилось. Уточните корзину перед оформлением.',
                ])
                ->with('stock_conflicts', $e->getItems());
        } catch (\App\Exceptions\DebtRestrictionException $e) {
            // Лестница долга: причина, сумма и что закрыто — клиенту, без угроз.
            return back()
                ->withErrors(['debt' => $e->getMessage()])
                ->with('debt_restriction', $e->toPayload());
        }

        // v16.9.0 (res-06): резервный заказ — сразу в рабочее место резервов.
        // Один заказ → его страница (там таймер и кнопки), несколько →
        // раздел «Заказы в резерве».
        $reserveOrder = $orders->first(fn (Order $o) => $o->reserve);

        if ($reserveOrder !== null) {
            $target = $orders->count() === 1
                ? redirect()->route('cabinet.orders.show', $reserveOrder)
                : redirect()->route('cabinet.reserves.index');

            return $target->with('success', $this->successMessage($orders))->with('order_placed', true);
        }

        // Несколько заказов (обычный / предзаказ / уценка) — в список заказов
        if ($orders->count() > 1) {
            return redirect()
                ->route('cabinet.orders.index')
                ->with('success', $this->successMessage($orders))->with('order_placed', true);
        }

        return redirect()
            ->route('cabinet.orders.show', $orders->first())
            ->with('success', $this->successMessage($orders))->with('order_placed', true);
    }

    /**
     * Сообщение после оформления: клиент должен сразу увидеть, что предзаказ
     * ушёл отдельным документом и сколько его ждать — а не узнать об этом
     * от менеджера через день.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Order>  $orders
     */
    private function successMessage(Collection $orders): string
    {
        $preorder = $orders->first(fn (Order $o) => $o->type === OrderType::PREORDER);

        if ($preorder === null) {
            return $orders->count() > 1 ? 'Заказы успешно оформлены!' : 'Заказ успешно оформлен!';
        }

        $lead = PreorderTerms::leadLabel();

        if ($orders->count() === 1) {
            return "Предзаказ оформлен. Товар заказываем у поставщика, ориентировочная поставка — {$lead}. ".Order::pendingNumberHint();
        }

        return "Оформлено документов: {$orders->count()}. Предзаказ — отдельно, ориентировочная поставка {$lead}. ".Order::pendingNumberHint();
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

        ['adjusted' => $adjusted, 'removed' => $removed, 'remaining_lines' => $remaining] = $this->normalizer->normalize($user, $cart);

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
        if ($remaining === 0) {
            return redirect()
                ->route('cart.index')
                ->with('warning', 'Корзина опустела после сверки с остатками ('.implode(', ', $parts).').');
        }

        return redirect()
            ->route('checkout.index')
            ->with('success', 'Корзина приведена к доступному ('.implode(', ', $parts).').');
    }
}

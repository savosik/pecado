<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CheckoutController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService
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

        return Inertia::render('User/Checkout/Index', [
            'cart' => [
                'id' => $cart->id,
                'name' => $cart->name,
            ],
            'cartDetails' => $cartDetails,
        ]);
    }
}

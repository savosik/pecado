<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /**
     * Получить сводку корзины текущего пользователя.
     * GET /api/cart/summary
     *
     * Возвращает список элементов корзины с product_id и quantity.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        // Получаем или создаём корзину по умолчанию
        $cart = $user->carts()->firstOrCreate(
            ['name' => 'default'],
            ['user_id' => $user->id]
        );

        $items = $cart->items()
            ->select('id', 'product_id', 'quantity')
            ->get();

        return response()->json([
            'items' => $items,
            'total_quantity' => $items->sum('quantity'),
        ]);
    }

    /**
     * Добавить товар в корзину.
     * POST /api/cart/items
     */
    public function addItem(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();

        $cart = $user->carts()->firstOrCreate(
            ['name' => 'default'],
            ['user_id' => $user->id]
        );

        // Если товар уже в корзине — увеличиваем количество
        $item = $cart->items()->where('product_id', $request->product_id)->first();

        if ($item) {
            $item->update([
                'quantity' => $item->quantity + $request->quantity,
            ]);
        } else {
            $item = $cart->items()->create([
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
            ]);
        }

        return response()->json([
            'id' => $item->id,
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
        ], 201);
    }

    /**
     * Обновить количество элемента корзины.
     * PATCH /api/cart/items/{item}
     */
    public function updateItem(Request $request, CartItem $item): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        // Проверяем что элемент принадлежит пользователю
        $cart = $item->cart;
        if ($cart->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещён');
        }

        $item->update([
            'quantity' => $request->quantity,
        ]);

        return response()->json([
            'id' => $item->id,
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
        ]);
    }

    /**
     * Удалить элемент из корзины.
     * DELETE /api/cart/items/{item}
     */
    public function removeItem(Request $request, CartItem $item): JsonResponse
    {
        // Проверяем что элемент принадлежит пользователю
        $cart = $item->cart;
        if ($cart->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещён');
        }

        $item->delete();

        return response()->json([
            'message' => 'Товар удалён из корзины',
        ]);
    }

    /**
     * Очистить корзину.
     * DELETE /api/cart/clear
     */
    public function clear(Request $request): JsonResponse
    {
        $user = $request->user();

        $cart = $user->carts()->where('name', 'default')->first();

        if ($cart) {
            $cart->items()->delete();
        }

        return response()->json([
            'message' => 'Корзина очищена',
        ]);
    }
}

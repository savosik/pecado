<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class OrderController extends Controller
{
    /**
     * Просмотр заказа.
     * GET /orders/{order}
     */
    public function show(Request $request, Order $order): InertiaResponse
    {
        $user = $request->user();

        // Убедиться, что заказ принадлежит текущему пользователю
        abort_unless($order->user_id === $user->id, 403);

        // Загрузить все связи
        $order->load([
            'company:id,name,legal_name,tax_id',
            'deliveryAddress:id,name,address',
            'children.items.product:id,name,sku,slug',
            'children.items.product.brand:id,name',
        ]);

        // Подготовить данные дочерних заказов
        $childOrders = $order->children->map(function ($child) {
            return [
                'id' => $child->id,
                'type' => $child->type?->value,
                'status' => $child->status?->value,
                'total_amount' => $child->total_amount,
                'items' => $child->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'price' => $item->price,
                        'quantity' => $item->quantity,
                        'subtotal' => $item->subtotal,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                            'slug' => $item->product->slug,
                            'brand' => $item->product->brand ? [
                                'name' => $item->product->brand->name,
                            ] : null,
                        ] : null,
                    ];
                }),
            ];
        });

        return Inertia::render('User/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'status' => $order->status?->value,
                'type' => $order->type?->value,
                'comment' => $order->comment,
                'total_amount' => $order->total_amount,
                'currency_code' => $order->currency_code,
                'created_at' => $order->created_at?->toISOString(),
                'company' => $order->company ? [
                    'id' => $order->company->id,
                    'name' => $order->company->name,
                    'legal_name' => $order->company->legal_name,
                    'tax_id' => $order->company->tax_id,
                ] : null,
                'delivery_address' => $order->deliveryAddress ? [
                    'id' => $order->deliveryAddress->id,
                    'name' => $order->deliveryAddress->name,
                    'address' => $order->deliveryAddress->address,
                ] : null,
                'children' => $childOrders,
            ],
        ]);
    }
}

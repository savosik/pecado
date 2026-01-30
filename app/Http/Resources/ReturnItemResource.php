<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'reason' => $this->reason?->value,
            'reason_comment' => $this->reason_comment,
            'price' => $this->price,
            'subtotal' => $this->subtotal,
            'product' => $this->whenLoaded('product', fn() => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'order' => $this->whenLoaded('order', fn() => [
                'id' => $this->order->id,
                'uuid' => $this->order->uuid,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}

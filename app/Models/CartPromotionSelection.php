<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Выбор клиента по награде акции внутри корзины.
 *
 * Сами промо-строки не хранятся — движок вычисляет их на каждый рендер корзины.
 * Здесь только то, что вычислить нельзя: какой товар клиент выбрал из нескольких
 * и от какой платной промо-позиции отказался.
 *
 * @property int $id
 * @property int $cart_id
 * @property int $promotion_rule_id
 * @property int $reward_index
 * @property int|null $product_id
 * @property bool $is_declined
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Cart $cart
 * @property-read \App\Models\PromotionRule $promotionRule
 * @property-read \App\Models\Product|null $product
 *
 * @mixin \Eloquent
 */
class CartPromotionSelection extends Model
{
    protected $fillable = [
        'cart_id',
        'promotion_rule_id',
        'reward_index',
        'product_id',
        'is_declined',
    ];

    protected function casts(): array
    {
        return [
            'reward_index' => 'integer',
            'is_declined' => 'boolean',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function promotionRule(): BelongsTo
    {
        return $this->belongsTo(PromotionRule::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

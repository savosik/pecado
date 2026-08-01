<?php

namespace App\Services\Promotion\DTO;

use App\Enums\OrderType;
use App\Enums\PromoKind;
use App\Models\Order;
use App\Models\Product;
use App\Services\Order\OrderLine;

/**
 * Результат расчёта акций для одного запроса клиентского API.
 *
 * Строки заказа отдаются сборщику до транзакции, а блок ответа собирается
 * после неё — `order_id` промо-позиции известен только после создания заказов.
 */
final readonly class ClientApiPromoResult
{
    /**
     * @param  array<string, list<OrderLine>>  $groups  строки по типу заказа
     * @param  list<array{reward: AppliedReward, product: Product}>  $issued
     * @param  list<array<string, mixed>>  $nearMiss
     * @param  array<string, string>  $warehouseComments  лист отбора по типу заказа
     */
    public function __construct(
        public array $groups,
        private array $issued,
        private array $nearMiss,
        public array $warehouseComments = [],
    ) {}

    public static function empty(): self
    {
        return new self(
            groups: [OrderType::PROMO->value => [], OrderType::PROMO_SAMPLE->value => []],
            issued: [],
            nearMiss: [],
        );
    }

    /**
     * Блок `promotions` ответа. Присутствует только при apply_promotions=true —
     * решение о том, добавлять ли его, принимает контроллер.
     *
     * @param  iterable<Order>  $orders  созданные заказы
     * @return array<string, mixed>
     */
    public function toResponse(iterable $orders): array
    {
        $orderIdByType = [];

        foreach ($orders as $order) {
            $orderIdByType[$order->type->value] = $order->id;
        }

        $applied = [];

        foreach ($this->issued as $entry) {
            /** @var AppliedReward $reward */
            $reward = $entry['reward'];
            /** @var Product $product */
            $product = $entry['product'];

            $type = $reward->promoKind === PromoKind::SAMPLE
                ? OrderType::PROMO_SAMPLE->value
                : OrderType::PROMO->value;

            $applied[] = [
                'rule_id' => $reward->ruleId,
                'promotion' => $reward->ruleName,
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $reward->quantity,
                'price' => round($reward->price, 2),
                'promo_kind' => $reward->promoKind->value,
                'order_id' => $orderIdByType[$type] ?? null,
            ];
        }

        return [
            'applied' => $applied,
            'near_miss' => $this->nearMiss,
        ];
    }
}

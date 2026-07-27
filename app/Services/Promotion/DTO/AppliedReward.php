<?php

namespace App\Services\Promotion\DTO;

use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;

/**
 * Промо-позиция, которую выдаёт сработавшее правило.
 *
 * В волне 1 правила работают в режиме `info`: награда возвращается, чтобы её показать,
 * но в корзину не добавляется. Различать помогает `ruleMode`.
 */
final readonly class AppliedReward
{
    public function __construct(
        public int $ruleId,
        public string $ruleName,
        public PromotionRuleMode $ruleMode,
        public int $rewardIndex,
        public int $productId,
        public int $quantity,
        public float $price,
        public PromoKind $promoKind,
        public ?int $warehouseId,
        public bool $optional,
        public bool $declined,
    ) {}

    /** Сумма промо-позиции в рублях. */
    public function total(): float
    {
        return round($this->quantity * $this->price, 2);
    }

    /** Позицию нужно реально положить в корзину/заказ. */
    public function isIssuable(): bool
    {
        return $this->ruleMode === PromotionRuleMode::ISSUE && ! $this->declined;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'rule_mode' => $this->ruleMode->value,
            'reward_index' => $this->rewardIndex,
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'total' => $this->total(),
            'promo_kind' => $this->promoKind->value,
            'warehouse_id' => $this->warehouseId,
            'optional' => $this->optional,
            'declined' => $this->declined,
        ];
    }
}

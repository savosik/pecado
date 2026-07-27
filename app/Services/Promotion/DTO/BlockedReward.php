<?php

namespace App\Services\Promotion\DTO;

use App\Enums\PromoBlockReason;

/**
 * Правило сработало, но промо-позиция не выдана.
 *
 * Только для админского предпросмотра: клиенту причины несрабатывания не показываем.
 */
final readonly class BlockedReward
{
    public function __construct(
        public int $ruleId,
        public string $ruleName,
        public ?int $rewardIndex,
        public ?int $productId,
        public PromoBlockReason $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'reward_index' => $this->rewardIndex,
            'product_id' => $this->productId,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
        ];
    }
}

<?php

namespace App\Services\Promotion\DTO;

use App\Enums\PromoBlockReason;

/**
 * Прогон одного правила по конкретной корзине — для админского предпросмотра.
 *
 * Отличие от PromotionEvaluation: считается по одному правилу и **без гейтов**
 * активности. Маркетологу нужно видеть, сработало бы правило, даже если оно
 * выключено или ещё не началось, — иначе «не сработало» и «не включено»
 * неразличимы, и разбираться будут разработчики.
 */
final readonly class RulePreview
{
    /**
     * @param  array<int, array{index: int, aggregate: string, operator: string, value: float, target: float, satisfied: bool, remaining: float}>  $conditions
     * @param  AppliedReward[]  $applied  Что было бы выдано
     * @param  BlockedReward[]  $blocked  Почему награда не выдаётся
     * @param  PromoBlockReason|null  $ruleBlock  Гейт уровня правила (канал, лимиты, режим)
     */
    public function __construct(
        public int $ruleId,
        public string $ruleName,
        public bool $fired,
        public string $conditionsMode,
        public array $conditions,
        public array $applied,
        public array $blocked,
        public ?PromoBlockReason $ruleBlock,
        public bool $audienceMatches,
        public bool $isActive,
        public bool $inPeriod,
        public bool $appliesToChannel,
        public int $lineCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'fired' => $this->fired,
            'conditions_mode' => $this->conditionsMode,
            'conditions' => $this->conditions,
            'applied' => array_map(fn (AppliedReward $reward) => $reward->toArray(), $this->applied),
            'blocked' => array_map(fn (BlockedReward $blocked) => $blocked->toArray(), $this->blocked),
            'rule_block' => $this->ruleBlock?->value,
            'rule_block_label' => $this->ruleBlock?->label(),
            'audience_matches' => $this->audienceMatches,
            'is_active' => $this->isActive,
            'in_period' => $this->inPeriod,
            'applies_to_channel' => $this->appliesToChannel,
            'line_count' => $this->lineCount,
        ];
    }
}

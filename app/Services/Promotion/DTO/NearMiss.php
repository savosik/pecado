<?php

namespace App\Services\Promotion\DTO;

/**
 * Правило не сработало, но клиент к нему близок: «доберите на X ₽».
 *
 * Считается только по правилам, товары которых уже есть в корзине, — иначе
 * подсказка про 150 000 ₽ висела бы у всех подряд.
 */
final readonly class NearMiss
{
    /**
     * @param  string  $aggregate  Чего не хватает: quantity (штуки) или amount (рубли)
     * @param  float  $current  Сколько набрано
     * @param  float  $target  Порог правила
     * @param  int[]  $rewardProductIds  Что будет выдано при достижении порога
     */
    public function __construct(
        public int $ruleId,
        public string $ruleName,
        public string $aggregate,
        public float $current,
        public float $target,
        public array $rewardProductIds = [],
    ) {}

    /** Сколько осталось до порога. */
    public function remaining(): float
    {
        return round(max(0, $this->target - $this->current), 2);
    }

    /** Доля выполнения порога, 0…1. */
    public function progress(): float
    {
        if ($this->target <= 0) {
            return 1.0;
        }

        return round(min(1, $this->current / $this->target), 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'aggregate' => $this->aggregate,
            'current' => $this->current,
            'target' => $this->target,
            'remaining' => $this->remaining(),
            'progress' => $this->progress(),
            'reward_product_ids' => $this->rewardProductIds,
        ];
    }
}

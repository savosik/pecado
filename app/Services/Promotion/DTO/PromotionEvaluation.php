<?php

namespace App\Services\Promotion\DTO;

/**
 * Результат расчёта акций по одному контексту.
 */
final readonly class PromotionEvaluation
{
    /**
     * @param  AppliedReward[]  $applied  Что выдаётся (в режиме info — что выдалось бы)
     * @param  NearMiss[]  $nearMiss  До чего клиенту немного не хватило
     * @param  BlockedReward[]  $blocked  Диагностика для админки, клиенту не показываем
     */
    public function __construct(
        public array $applied = [],
        public array $nearMiss = [],
        public array $blocked = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function hasApplied(): bool
    {
        return $this->applied !== [];
    }

    /**
     * Награды, которые действительно нужно положить в корзину или заказ.
     *
     * @return AppliedReward[]
     */
    public function issuable(): array
    {
        return array_values(array_filter($this->applied, fn (AppliedReward $reward) => $reward->isIssuable()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'applied' => array_map(fn (AppliedReward $reward) => $reward->toArray(), $this->applied),
            'near_miss' => array_map(fn (NearMiss $miss) => $miss->toArray(), $this->nearMiss),
            'blocked' => array_map(fn (BlockedReward $blocked) => $blocked->toArray(), $this->blocked),
        ];
    }
}

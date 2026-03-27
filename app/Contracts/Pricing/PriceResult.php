<?php

namespace App\Contracts\Pricing;

/**
 * DTO для результата расчёта цены.
 * Содержит базовую цену, индивидуальную цену (если есть), и процент скидки.
 */
class PriceResult
{
    public function __construct(
        public readonly float $basePrice,
        public readonly ?float $individualPrice,
        public readonly float $discountPercent,
        public readonly bool $hasDiscount,
    ) {}

    /**
     * Итоговая цена для отображения.
     */
    public function getDisplayPrice(): float
    {
        return $this->individualPrice ?? $this->basePrice;
    }

    /**
     * Создать из базовой цены (без скидки).
     */
    public static function withoutDiscount(float $basePrice): self
    {
        return new self(
            basePrice: $basePrice,
            individualPrice: null,
            discountPercent: 0,
            hasDiscount: false,
        );
    }

    /**
     * Создать с индивидуальной ценой.
     */
    public static function withIndividualPrice(float $basePrice, float $individualPrice): self
    {
        $discountPercent = $basePrice > 0
            ? round((1 - $individualPrice / $basePrice) * 100, 2)
            : 0;

        return new self(
            basePrice: $basePrice,
            individualPrice: $individualPrice,
            discountPercent: max(0, $discountPercent),
            hasDiscount: $discountPercent > 0,
        );
    }

    /**
     * Конвертировать в массив (для API / Inertia).
     */
    public function toArray(): array
    {
        return [
            'base_price' => $this->basePrice,
            'individual_price' => $this->individualPrice,
            'discount_percent' => $this->discountPercent,
            'has_discount' => $this->hasDiscount,
            'display_price' => $this->getDisplayPrice(),
        ];
    }
}

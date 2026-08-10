<?php

namespace App\Services\Delivery;

use App\Models\Shipment;

/**
 * Вес реализации по её товарам.
 *
 * Вес берётся с карточки товара: `products.weight_gross` в килограммах (так его
 * присылает 1С), а перевозчику нужны граммы. Товаров без веса в базе много,
 * поэтому предусмотрен фолбэк — молча слать ноль нельзя: ApiShip ошибки не даст,
 * но перевозчик посчитает тариф по объёмному весу и выставит другой счёт.
 */
class DeliveryWeightCalculator
{
    public function __construct(private readonly ApiShipSettings $settings) {}

    /**
     * Вес реализации в граммах.
     */
    public function forShipment(Shipment $shipment): int
    {
        return $this->breakdown($shipment)['weight'];
    }

    /**
     * Вес нескольких реализаций в граммах.
     *
     * @param  iterable<Shipment>  $shipments
     */
    public function forShipments(iterable $shipments): int
    {
        $total = 0;

        foreach ($shipments as $shipment) {
            $total += $this->forShipment($shipment);
        }

        return $total;
    }

    /**
     * Вес плюс список позиций, у которых веса нет.
     *
     * Список нужен интерфейсу: кладовщик должен видеть, что часть груза
     * посчитана по умолчанию, и при необходимости взвесить коробку сам.
     *
     * @return array{weight: int, missing: list<string>}
     */
    public function breakdown(Shipment $shipment): array
    {
        $fallback = $this->settings->int('default_item_weight_grams', 500);
        $weight = 0;
        $missing = [];

        $shipment->loadMissing('items.product');

        foreach ($shipment->items as $item) {
            $quantity = max(1, (int) $item->quantity);
            $product = $item->product;

            // weight_gross приоритетнее: перевозчик везёт товар в упаковке.
            $kilograms = (float) ($product?->weight_gross ?: $product?->weight_net ?: 0);

            if ($kilograms <= 0) {
                $weight += $fallback * $quantity;
                $missing[] = (string) ($item->product_name_snapshot ?: $product?->name ?: 'Позиция без наименования');

                continue;
            }

            $weight += (int) round($kilograms * 1000) * $quantity;
        }

        return [
            'weight' => $weight,
            'missing' => array_values(array_unique($missing)),
        ];
    }
}

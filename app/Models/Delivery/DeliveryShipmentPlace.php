<?php

namespace App\Models\Delivery;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Грузовое место (коробка) отправки.
 *
 * Единицы жёстко заданы ApiShip: вес в граммах, габариты в сантиметрах.
 *
 * @property int $id
 * @property int $number
 * @property int $weight
 *
 * @mixin \Eloquent
 */
class DeliveryShipmentPlace extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'delivery_shipment_id',
        'number',
        'weight',
        'length',
        'width',
        'height',
        'barcode',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'weight' => 'integer',
            'length' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<DeliveryShipment, $this> */
    public function deliveryShipment(): BelongsTo
    {
        return $this->belongsTo(DeliveryShipment::class);
    }

    /**
     * Объёмный вес места в граммах по формуле перевозчиков (Д×Ш×В / 5000, кг).
     *
     * Тариф считается по большему из фактического и объёмного веса — кладовщику
     * это надо видеть до отправки заявки, а не в счёте через месяц.
     */
    public function volumetricWeight(): int
    {
        if (! $this->length || ! $this->width || ! $this->height) {
            return 0;
        }

        return (int) round($this->length * $this->width * $this->height / 5000 * 1000);
    }
}

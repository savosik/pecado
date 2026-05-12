<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $attribute_id
 * @property int|null $attribute_value_id
 * @property string|null $text_value
 * @property numeric|null $number_value
 * @property bool|null $boolean_value
 * @property \Illuminate\Support\Carbon|null $datetime_value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Attribute $attribute
 * @property-read \App\Models\AttributeValue|null $attributeValue
 * @property-read \App\Models\Product $product
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereAttributeValueId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereBooleanValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereDatetimeValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereNumberValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereTextValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductAttributeValue whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProductAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'attribute_id',
        'attribute_value_id',
        'text_value',
        'number_value',
        'boolean_value',
        'datetime_value',
    ];

    protected $casts = [
        'number_value' => 'decimal:4',
        'boolean_value' => 'boolean',
        'datetime_value' => 'datetime',
    ];

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the attribute.
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * Get the attribute value (for select type).
     */
    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class);
    }

    /**
     * Get the actual value based on attribute type.
     */
    public function getValue(): mixed
    {
        $type = $this->attribute->type ?? 'string';

        return match ($type) {
            'select' => $this->attributeValue?->value,
            'number' => $this->number_value,
            'boolean' => $this->boolean_value,
            'date-time' => $this->datetime_value,
            default => $this->text_value,
        };
    }

    /**
     * Get the formatted value with unit if applicable.
     */
    public function getFormattedValue(): string
    {
        $value = $this->getValue();
        $unit = $this->attribute->unit;

        if ($value === null) {
            return '';
        }

        if ($this->attribute->isBoolean()) {
            return $value ? 'Да' : 'Нет';
        }

        if ($this->attribute->type === 'date-time' && $value instanceof \Carbon\Carbon) {
            return $value->format('d.m.Y H:i');
        }

        return $unit ? "{$value} {$unit}" : (string) $value;
    }
}

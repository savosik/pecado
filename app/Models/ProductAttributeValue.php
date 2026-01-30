<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'number_value' => 'decimal:4',
        'boolean_value' => 'boolean',
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

        return $unit ? "{$value} {$unit}" : (string) $value;
    }
}

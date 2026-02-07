<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Attribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'unit',
        'is_filterable',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the predefined values for this attribute.
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order');
    }

    /**
     * Get the categories associated with this attribute.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Get the products that have this attribute.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_attribute_values')
            ->withPivot(['attribute_value_id', 'text_value', 'number_value', 'boolean_value'])
            ->withTimestamps();
    }

    /**
     * Check if attribute is of type 'select'.
     */
    public function isSelect(): bool
    {
        return $this->type === 'select';
    }

    /**
     * Check if attribute is of type 'string'.
     */
    public function isString(): bool
    {
        return $this->type === 'string';
    }

    /**
     * Check if attribute is of type 'number'.
     */
    public function isNumber(): bool
    {
        return $this->type === 'number';
    }

    /**
     * Check if attribute is of type 'boolean'.
     */
    public function isBoolean(): bool
    {
        return $this->type === 'boolean';
    }
}

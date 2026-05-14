<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $external_id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property string|null $unit
 * @property bool $is_filterable
 * @property bool $is_active
 * @property bool $show_on_site
 * @property bool $show_in_export
 * @property bool $is_variant_forming
 * @property int $sort_order
 * @property int|null $attribute_group_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\AttributeGroup|null $attributeGroup
 * @property-read \Kalnoy\Nestedset\Collection<int, \App\Models\Category> $categories
 * @property-read int|null $categories_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AttributeValue> $values
 * @property-read int|null $values_count
 *
 * @method static Builder<static>|Attribute active()
 * @method static Builder<static>|Attribute newModelQuery()
 * @method static Builder<static>|Attribute newQuery()
 * @method static Builder<static>|Attribute query()
 * @method static Builder<static>|Attribute whereAttributeGroupId($value)
 * @method static Builder<static>|Attribute whereCreatedAt($value)
 * @method static Builder<static>|Attribute whereExternalId($value)
 * @method static Builder<static>|Attribute whereId($value)
 * @method static Builder<static>|Attribute whereIsActive($value)
 * @method static Builder<static>|Attribute whereIsFilterable($value)
 * @method static Builder<static>|Attribute whereIsVariantForming($value)
 * @method static Builder<static>|Attribute whereName($value)
 * @method static Builder<static>|Attribute whereShowInExport($value)
 * @method static Builder<static>|Attribute whereShowOnSite($value)
 * @method static Builder<static>|Attribute whereSlug($value)
 * @method static Builder<static>|Attribute whereSortOrder($value)
 * @method static Builder<static>|Attribute whereType($value)
 * @method static Builder<static>|Attribute whereUnit($value)
 * @method static Builder<static>|Attribute whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Attribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'name',
        'slug',
        'type',
        'unit',
        'is_filterable',
        'is_active',
        'show_on_site',
        'show_in_export',
        'is_variant_forming',
        'is_partner_only',
        'sort_order',
        'attribute_group_id',
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
            'is_active' => 'boolean',
            'show_on_site' => 'boolean',
            'show_in_export' => 'boolean',
            'is_variant_forming' => 'boolean',
            'is_partner_only' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Имя считается «русским» если содержит хотя бы одну кириллическую букву.
     * Имена на латинице с подчёркиваниями приходят из 1С как служебные и по умолчанию деактивируются.
     */
    public static function hasCyrillicName(?string $name): bool
    {
        return $name !== null && preg_match('/\p{Cyrillic}/u', $name) === 1;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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
     * Get the attribute group this attribute belongs to.
     */
    public function attributeGroup(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class);
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Attribute> $attributes
 * @property-read int|null $attributes_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeGroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeGroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeGroup query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeGroup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeGroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeGroup whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeGroup whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeGroup whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class AttributeGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Get the attributes belonging to this group.
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class);
    }
}

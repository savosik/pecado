<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * Get the attributes belonging to this group.
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class);
    }
}

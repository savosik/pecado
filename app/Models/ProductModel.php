<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'code',
        'name',

    ];



    /**
     * Get the products for this model.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'model_id');
    }
}

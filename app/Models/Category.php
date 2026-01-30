<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\NodeTrait;
use Spatie\Tags\HasTags;

class Category extends Model
{
    use HasFactory;
    use HasTags;
    use NodeTrait;

    protected $fillable = [
        'name',
        'parent_id',
        'external_id',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
    ];

    /**
     * Get the products for this category.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product');
    }
}

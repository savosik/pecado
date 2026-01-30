<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Promotion extends Model implements HasMedia
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \App\Traits\HasContentMedia;

    protected $fillable = [
        'name',
        'meta_title',
        'meta_description',
        'description',
    ];



    /**
     * Get the products that belong to the promotion.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_promotion');
    }
}

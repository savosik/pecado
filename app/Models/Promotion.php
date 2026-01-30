<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Promotion extends Model implements HasMedia
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use InteractsWithMedia;

    protected $fillable = [
        'name',
        'meta_title',
        'meta_description',
        'description',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('list-item')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'])
            ->singleFile();

        $this->addMediaCollection('detail-item-desktop')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'])
            ->singleFile();

        $this->addMediaCollection('detail-item-mobile')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'])
            ->singleFile();
    }

    /**
     * Get the products that belong to the promotion.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_promotion');
    }
}

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

    public function registerMediaCollections(): void
    {
        // Вызываем коллекции из трейта HasContentMedia (list-item, detail-item-desktop, detail-item-mobile)
        $this->addMediaCollection('list-item')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'])
            ->singleFile();

        $this->addMediaCollection('detail-item-desktop')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'])
            ->singleFile();

        $this->addMediaCollection('detail-item-mobile')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'])
            ->singleFile();

        // Дополнительная коллекция для галереи (только для Promotion)
        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml']);
    }

    /**
     * Get the products that belong to the promotion.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_promotion');
    }
}

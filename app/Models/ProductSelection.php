<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ProductSelection extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('desktop')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
                'video/mp4', 'video/webm', 'video/quicktime'
            ])
            ->singleFile();

        $this->addMediaCollection('mobile')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
                'video/mp4', 'video/webm', 'video/quicktime'
            ])
            ->singleFile();
    }

    /**
     * Get the products that belong to the product selection.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_product_selection');
    }
}

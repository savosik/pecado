<?php

namespace App\Models;

use App\Helpers\SearchHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\NodeTrait;
use Laravel\Scout\Searchable;
use Spatie\Tags\HasTags;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Category extends Model implements HasMedia
{
    use HasFactory;
    use HasTags;
    use NodeTrait;
    use InteractsWithMedia;
    use Searchable {
        Searchable::usesSoftDelete insteadof NodeTrait;
    }

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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('icon')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'
            ])
            ->singleFile();
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $name = $this->name ?? '';

        return [
            'id' => (int) $this->id,
            'name' => $name,
            'name_translit' => SearchHelper::transliterate($name),
            'name_cyrillic' => SearchHelper::transliterateToCyrillic($name),
            'name_layout' => SearchHelper::convertLayout($name),
        ];
    }
}

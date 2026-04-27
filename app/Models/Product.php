<?php

namespace App\Models;

use App\Helpers\SearchHelper;
use App\Models\Scopes\HiddenScope;
use App\Models\Traits\ProductQueryScopes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\HasTags;

class Product extends Model implements HasMedia
{
    use HasFactory, HasTags, InteractsWithMedia, ProductQueryScopes, Searchable;

    /**
     * Автоматическая очистка HTML-сущностей при сохранении.
     * 1С отправляет названия с &amp;quot; &amp;amp; и т.п. — декодируем перед записью.
     */
    protected static function booted(): void
    {
        // v10: Скрытые товары не отображаются на сайте.
        // В Admin-контроллерах снимается через withoutGlobalScope(HiddenScope::class).
        static::addGlobalScope(new HiddenScope);

        static::saving(function (Product $product) {
            foreach (['name', 'description', 'short_description'] as $field) {
                if ($product->isDirty($field) && is_string($product->{$field})) {
                    $product->{$field} = html_entity_decode($product->{$field}, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            // Автогенерация slug, если не задан
            if (empty($product->slug) && ! empty($product->name)) {
                $transliterated = \App\Helpers\SearchHelper::transliterate($product->name);
                $baseSlug = \Illuminate\Support\Str::slug($transliterated);

                if (empty($baseSlug)) {
                    $baseSlug = 'product-'.($product->sku ?: \Illuminate\Support\Str::random(8));
                }

                // Добавляем суффикс из внутреннего кода для уникальности
                if ($product->sku) {
                    $baseSlug .= '-'.\Illuminate\Support\Str::slug($product->sku);
                }

                // Гарантируем уникальность
                $slug = $baseSlug;
                $counter = 1;
                while (static::where('slug', $slug)->where('id', '!=', $product->id ?? 0)->exists()) {
                    $slug = $baseSlug.'-'.$counter++;
                }
                $product->slug = $slug;
            }
        });
    }

    protected $fillable = [
        'name',
        'base_price',
        'external_id',
        'sex_opt_id',
        'is_new',
        'is_bestseller',
        'code',
        'sku',
        'variant_name',
        'slug',
        'url',
        'barcode',
        'tnved',
        'weight_gross',
        'weight_net',
        'width',
        'height',
        'depth',
        'hs_code',
        'abc_xyz',
        'turnover',
        'erp_created_at',
        'erp_updated_at',
        'is_marked',
        'is_liquidation',
        'for_marketplaces',
        'hidden',
        'description',
        'description_html',
        'rich_content',
        'rich_content_generated_at',
        'rich_content_generation_failed_at',
        'rich_content_generation_attempts',
        'short_description',
        'meta_title',
        'meta_description',
        'category_id',
        'brand_id',
        'model_id',
        'size_chart_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_new' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_marked' => 'boolean',
            'is_liquidation' => 'boolean',
            'for_marketplaces' => 'boolean',
            'hidden' => 'boolean',
            'pros_cons' => 'array',
            'rich_content' => 'array',
            'rich_content_generated_at' => 'datetime',
            'rich_content_generation_failed_at' => 'datetime',
            'weight_gross' => 'decimal:3',
            'weight_net' => 'decimal:3',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'depth' => 'decimal:2',
            'turnover' => 'decimal:4',
            'erp_created_at' => \App\Casts\ErpDatetime::class,
            'erp_updated_at' => \App\Casts\ErpDatetime::class,
        ];
    }

    /**
     * Отрендеренный markdown описания в HTML (для публичной выдачи на фронт).
     */
    public function getDescriptionRenderedAttribute(): ?string
    {
        return app(\App\Services\MarkdownRenderer::class)->toHtml($this->description);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('main')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'])
            ->singleFile();

        $this->addMediaCollection('additional')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml']);

        $this->addMediaCollection('video')
            ->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime'])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(300)
            ->height(450)
            ->performOnCollections('main', 'additional');
    }

    /**
     * Get the category for the product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the brand for the product.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the product model (group) for the product.
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'model_id');
    }

    /**
     * Get the size chart for the product.
     */
    public function sizeChart(): BelongsTo
    {
        return $this->belongsTo(SizeChart::class);
    }

    /**
     * Get the certificates for the product.
     */
    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(Certificate::class, 'product_certificate');
    }

    /**
     * Get the product selections for the product.
     */
    public function productSelections(): BelongsToMany
    {
        return $this->belongsToMany(ProductSelection::class, 'product_product_selection')
            ->withPivot('featured');
    }

    /**
     * Get the barcodes for the product.
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /**
     * Get the attribute values for the product.
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Get the attributes for the product through pivot.
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_attribute_values')
            ->withPivot(['attribute_value_id', 'text_value', 'number_value', 'boolean_value'])
            ->withTimestamps();
    }

    /**
     * Get the individual prices for the product (v7: from 1С via MinIO).
     */
    public function individualPrices(): HasMany
    {
        return $this->hasMany(IndividualPrice::class, 'product_uuid', 'external_id');
    }

    /**
     * Get the warehouses with stock for the product.
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'product_warehouse')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * Get the users who have favorited this product.
     */
    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites');
    }

    /**
     * Get the users who have this product in their wishlist.
     */
    public function wishlistedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wishlist_items');
    }

    /**
     * Get the order items for the product.
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the promotions that belong to the product.
     */
    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'product_promotion');
    }

    /**
     * Get the return items for the product.
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    /**
     * Define if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->hidden;
    }

    /**
     * Переопределение метода Spatie\MediaLibrary\InteractsWithMedia.
     *
     * При удалении товара мы удаляем только записи в таблице media,
     * а файлы в MinIO остаются (наш персистентный кэш по артикулу).
     *
     * @return $this
     */
    public function deleteAllMedia(): self
    {
        $this->media()->delete();

        return $this;
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
            'brand' => $this->brand?->name,
            'brand_translit' => SearchHelper::transliterate($this->brand?->name ?? ''),
            'brand_cyrillic' => SearchHelper::transliterateToCyrillic($this->brand?->name ?? ''),
            'brand_layout' => SearchHelper::convertLayout($this->brand?->name ?? ''),
            'category' => $this->category?->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'code' => $this->code,
            'barcodes' => $this->barcodes->pluck('barcode')->toArray(),
            'base_price' => (float) $this->base_price,
        ];
    }
}

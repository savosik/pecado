<?php

namespace App\Models;

use App\Helpers\SearchHelper;
use App\Models\Scopes\HiddenScope;
use App\Models\Traits\ProductQueryScopes;
use App\Services\ProductExport\Concerns\HasExportRowCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\HasTags;

/**
 * @property int $id
 * @property string $name
 * @property numeric $base_price
 * @property string|null $external_id
 * @property string|null $sex_opt_id
 * @property bool $is_new
 * @property bool $is_bestseller
 * @property string|null $code
 * @property string|null $sku
 * @property string|null $variant_name
 * @property string|null $slug
 * @property string|null $url
 * @property string|null $barcode
 * @property string|null $tnved
 * @property numeric|null $weight_gross
 * @property numeric|null $weight_net
 * @property numeric|null $width
 * @property numeric|null $height
 * @property numeric|null $depth
 * @property string|null $hs_code
 * @property string|null $abc_xyz
 * @property numeric|null $turnover
 * @property bool $is_marked
 * @property bool $is_liquidation
 * @property bool $for_marketplaces
 * @property string|null $description
 * @property string|null $description_html
 * @property array<array-key, mixed>|null $rich_content
 * @property \Illuminate\Support\Carbon|null $rich_content_generated_at
 * @property \Illuminate\Support\Carbon|null $rich_content_generation_failed_at
 * @property int $rich_content_generation_attempts
 * @property string|null $short_description
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $meta_keywords
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property int|null $model_id
 * @property bool $hidden v10: Скрыть в интернете — товар не отображается на сайте
 * @property int|null $size_chart_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $erp_created_at
 * @property \Carbon\Carbon|null $erp_updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductAttributeValue> $attributeValues
 * @property-read int|null $attribute_values_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Attribute> $attributes
 * @property-read int|null $attributes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductBarcode> $barcodes
 * @property-read int|null $barcodes_count
 * @property-read \App\Models\Brand|null $brand
 * @property-read \App\Models\Category|null $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Certificate> $certificates
 * @property-read int|null $certificates_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $favoritedByUsers
 * @property-read int|null $favorited_by_users_count
 * @property-read string|null $description_rendered
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\IndividualPrice> $individualPrices
 * @property-read int|null $individual_prices_count
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \App\Models\ProductModel|null $model
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $orderItems
 * @property-read int|null $order_items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductSelection> $productSelections
 * @property-read int|null $product_selections_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Promotion> $promotions
 * @property-read int|null $promotions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReturnItem> $returnItems
 * @property-read int|null $return_items_count
 * @property \Illuminate\Database\Eloquent\Collection<int, \Spatie\Tags\Tag> $tags
 * @property-read \App\Models\SizeChart|null $sizeChart
 * @property-read int|null $tags_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Warehouse> $warehouses
 * @property-read int|null $warehouses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $wishlistedByUsers
 * @property-read int|null $wishlisted_by_users_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product available(?int $regionId = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product byAttributes(array $valueIds, bool $any = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product byInlineAttributes(array $filters)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product byPrice(?float $min = null, ?float $max = null)
 * @method static \Database\Factories\ProductFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product inBrands(array $ids)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product inCategories(array $ids, bool $descendants = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product inCategory(\App\Models\Category|int $category, bool $descendants = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product inCollections(array $ids)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product inFavourites(int $userId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product inSale(bool $value = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product inStock(string $mode, ?int $regionId = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product search(string $q)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereAbcXyz($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBarcode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBasePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBrandId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDepth($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDescriptionHtml($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereErpCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereErpUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereForMarketplaces($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereHeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereHidden($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereHsCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsBestseller($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsLiquidation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsMarked($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsNew($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereMetaDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereMetaTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereModelId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereRichContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereRichContentGeneratedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereRichContentGenerationAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereRichContentGenerationFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSexOptId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereShortDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSizeChartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereTnved($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereTurnover($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereVariantName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereWeightGross($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereWeightNet($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereWidth($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withAllTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withAllTagsOfAnyType($tags)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withAnyTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withAnyTagsOfAnyType($tags)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withAnyTagsOfType(array|string $type)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withoutTags(\ArrayAccess|\Spatie\Tags\Tag|array|string $tags, ?string $type = null)
 *
 * @mixin \Eloquent
 */
class Product extends Model implements HasMedia
{
    use HasExportRowCache, HasFactory, HasTags, InteractsWithMedia, ProductQueryScopes, Searchable;

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
        'meta_keywords',
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

        $this->addMediaConversion('large')
            ->fit(Fit::Max, 1200, 1600)
            ->format('jpg')
            ->quality(80)
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

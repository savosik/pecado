<?php

namespace App\Models;

use App\Enums\DefectClosedReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Партия некондиции (уценки).
 *
 * Одна запись = один вид дефекта у одного товара + количество + общие фотографии.
 * Учёт ведёт кладовщик в /wms/, цену и публикацию — закупщик в /admin/.
 *
 * Партия продаётся, когда одновременно: назначена цена, включена публикация,
 * партия не закрыта и остаток за вычетом резерва больше нуля — см.
 * App\Services\Defect\DefectStockService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property int $warehouse_id
 * @property string $defect_description
 * @property int $quantity
 * @property numeric|null $price
 * @property bool $is_published
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property DefectClosedReason|null $closed_reason
 * @property int|null $created_by
 * @property int|null $priced_by
 * @property-read int|null $available_quantity Свободный остаток; заполняется DefectStockService::sellableForProduct, в БД не хранится
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\User|null $pricer
 *
 * @method static \Database\Factories\ProductDefectFactory factory($count = null, $state = [])
 * @method static Builder<static>|ProductDefect open()
 * @method static Builder<static>|ProductDefect sellable()
 *
 * @mixin \Eloquent
 */
class ProductDefect extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    /** Коллекция фотографий дефекта. */
    public const MEDIA_COLLECTION = 'defect_photos';

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'defect_description',
        'quantity',
        'price',
        'is_published',
        'closed_at',
        'closed_reason',
        'created_by',
        'priced_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'is_published' => 'boolean',
            'closed_at' => 'datetime',
            'closed_reason' => DefectClosedReason::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $defect) {
            if (! $defect->uuid) {
                $defect->uuid = (string) Str::uuid();
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // performOnCollections() первым, а не последним в цепочке: после width()/height()
        // статический анализ теряет тип Conversion (см. ту же ошибку в Product.php).
        $this->addMediaConversion('thumb')
            ->performOnCollections(self::MEDIA_COLLECTION)
            ->width(300)
            ->height(300);
    }

    // ────────────────────────────────────────────
    // Связи
    // ────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pricer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'priced_by');
    }

    // ────────────────────────────────────────────
    // Скоупы
    // ────────────────────────────────────────────

    /** Партия не закрыта: не распродана и не списана. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /**
     * Партия допущена в продажу: открыта, с ценой и включённой публикацией.
     *
     * Остаток за вычетом резерва здесь не проверяется — это отдельный запрос,
     * см. DefectStockService::availableMap().
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->whereNull('closed_at')
            ->where('is_published', true)
            ->whereNotNull('price');
    }

    // ────────────────────────────────────────────
    // Методы
    // ────────────────────────────────────────────

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /** Готова ли партия к публикации: без цены публиковать нельзя. */
    public function canBePublished(): bool
    {
        return $this->price !== null && ! $this->isClosed();
    }

    /** Закрыть партию: распродана или списана вручную. */
    public function close(DefectClosedReason $reason): void
    {
        $this->forceFill([
            'closed_at' => now(),
            'closed_reason' => $reason,
        ])->save();
    }
}

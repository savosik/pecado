<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Services\ProductExport\FiltersTextRenderer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $client_user_id
 * @property string $name
 * @property string $hash
 * @property ExportFormat $format
 * @property string|null $preset Preset type key (yml, shopify, woocommerce, etc.) — null for custom exports
 * @property array<array-key, mixed>|null $filters
 * @property string|null $filters_text Денормализованные имена брендов/категорий/складов/сертификатов из filters JSON для LIKE-поиска
 * @property array<array-key, mixed> $fields
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_downloaded_at
 * @property \Illuminate\Support\Carbon|null $cached_at When the cached export file was last generated
 * @property string $status
 * @property int|null $last_run_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $clientUser
 * @property-read string $download_url
 * @property-read \App\Models\ProductExportRun|null $lastRun
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductExportRun> $runs
 * @property-read int|null $runs_count
 * @property-read \App\Models\User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereCachedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereClientUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereFields($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereFilters($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereFiltersText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereFormat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereLastDownloadedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereLastRunId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport wherePreset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExport whereUserId($value)
 *
 * @mixin \Eloquent
 */
class ProductExport extends Model
{
    use HasFactory;

    public const STATUS_IDLE = 'idle';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'client_user_id',
        'name',
        'hash',
        'format',
        'preset',
        'filters',
        'filters_text',
        'fields',
        'is_active',
        'last_downloaded_at',
        'cached_at',
        'data_version_at',
        'estimated_rows',
        'status',
        'last_run_id',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'fields' => 'array',
            'is_active' => 'boolean',
            'format' => ExportFormat::class,
            'last_downloaded_at' => 'datetime',
            'cached_at' => 'datetime',
            'data_version_at' => 'datetime',
            'estimated_rows' => 'integer',
        ];
    }

    protected $attributes = [
        'status' => self::STATUS_IDLE,
    ];

    /**
     * Check if this export is a preset (not a custom export).
     */
    public function isPreset(): bool
    {
        return ! empty($this->preset);
    }

    /**
     * Get the path for the cached export file.
     */
    public function getCacheFilePath(): string
    {
        return storage_path("app/exports/{$this->hash}");
    }

    /**
     * Check if a valid cache file exists.
     *
     * Дефолтный TTL — 5 минут. Партнёрские прайсы обновляются по расписанию
     * `exports:warm` каждые 15 минут, а ERP-апдейты цен/остатков прилетают
     * непрерывно — короткий TTL минимизирует окно «несвежих» данных в файле.
     *
     * Дополнительно проверяется ProductExportDataVersion: если ERP помечал
     * изменения каталога после момента генерации (data_version_at) — кеш
     * не считается свежим, даже если cached_at < 5 минут. Без этой проверки
     * после ERP-апдейта пришлось бы ждать обычный TTL.
     */
    public function hasFreshCache(int $maxAgeMinutes = 5): bool
    {
        if (! $this->cached_at) {
            return false;
        }

        $filePath = $this->getCacheFilePath();
        if (! file_exists($filePath)) {
            return false;
        }

        // Если глобальная версия данных каталога обновилась после генерации —
        // файл устарел независимо от cached_at. data_version_at = null — это
        // pre-PR4 кеш (или ещё ни разу не bump'ались), для grace migration
        // оставляем такой кеш валидным и проверяем только по cached_at.
        $currentVersion = app(\App\Services\ProductExport\ProductExportDataVersion::class)->current();
        if ($currentVersion->getTimestamp() > 0 && $this->data_version_at) {
            if ($currentVersion->gt($this->data_version_at)) {
                return false;
            }
        }

        return $this->cached_at->diffInMinutes(now()) < $maxAgeMinutes;
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ProductExport $model) {
            if (empty($model->hash)) {
                $model->hash = hash('sha256', $model->user_id.microtime(true).Str::random(32));
            }
        });

        static::saving(function (ProductExport $model) {
            if ($model->isDirty('filters') || ! $model->exists) {
                $model->filters_text = app(FiltersTextRenderer::class)->render($model->filters ?? []);

                // Пересчитать оценочное число строк под новыми фильтрами —
                // на каталоге 5к count() с whereHas стоит копейки (~50 мс).
                // Игнорируем ошибки: если ProductExportService::buildQuery
                // упадёт на странных фильтрах — лучше оставить старое значение,
                // чем заблокировать save.
                try {
                    $model->estimated_rows = app(\App\Services\ProductExportService::class)
                        ->buildQuery($model->filters ?? [])
                        ->count();
                } catch (\Throwable) {
                    // оставляем предыдущее значение
                }
            }
        });
    }

    /**
     * Get the admin user that owns the export.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the client user whose prices and stock are used for this export.
     */
    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * История запусков генерации.
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ProductExportRun::class);
    }

    /**
     * Последний запуск (для UI: статус, длительность, ошибка).
     */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(ProductExportRun::class, 'last_run_id');
    }

    /**
     * Get the download URL for this export.
     */
    public function getDownloadUrlAttribute(): string
    {
        return url("/export/{$this->hash}");
    }

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = ['download_url'];
}

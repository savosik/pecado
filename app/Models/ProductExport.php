<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Services\ProductExport\FiltersTextRenderer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
     */
    public function hasFreshCache(int $maxAgeMinutes = 10): bool
    {
        if (! $this->cached_at) {
            return false;
        }

        $filePath = $this->getCacheFilePath();
        if (! file_exists($filePath)) {
            return false;
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

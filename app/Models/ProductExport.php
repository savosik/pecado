<?php

namespace App\Models;

use App\Enums\ExportFormat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_user_id',
        'name',
        'hash',
        'format',
        'preset',
        'filters',
        'fields',
        'is_active',
        'last_downloaded_at',
        'cached_at',
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

    /**
     * Check if this export is a preset (not a custom export).
     */
    public function isPreset(): bool
    {
        return !empty($this->preset);
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
    public function hasFreshCache(int $maxAgeHours = 4): bool
    {
        if (!$this->cached_at) {
            return false;
        }

        $filePath = $this->getCacheFilePath();
        if (!file_exists($filePath)) {
            return false;
        }

        return $this->cached_at->diffInHours(now()) < $maxAgeHours;
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ProductExport $model) {
            if (empty($model->hash)) {
                $model->hash = hash('sha256', $model->user_id . microtime(true) . Str::random(32));
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

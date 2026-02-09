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
        'filters',
        'fields',
        'is_active',
        'last_downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'fields' => 'array',
            'is_active' => 'boolean',
            'format' => ExportFormat::class,
            'last_downloaded_at' => 'datetime',
        ];
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

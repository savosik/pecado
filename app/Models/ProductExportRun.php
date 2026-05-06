<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * История одного запуска генерации выгрузки.
 *
 * Один ProductExport имеет много ProductExportRun.
 * ProductExport::last_run_id указывает на последний (для быстрого доступа в UI).
 */
class ProductExportRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'product_export_id',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'rows_count',
        'bytes',
        'error_message',
        'error_count',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'rows_count' => 'integer',
            'bytes' => 'integer',
            'error_count' => 'integer',
        ];
    }

    public function productExport(): BelongsTo
    {
        return $this->belongsTo(ProductExport::class);
    }
}

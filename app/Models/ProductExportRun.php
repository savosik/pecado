<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * История одного запуска генерации выгрузки.
 *
 * Один ProductExport имеет много ProductExportRun.
 * ProductExport::last_run_id указывает на последний (для быстрого доступа в UI).
 *
 * @property int $id
 * @property int $product_export_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $rows_count
 * @property int|null $bytes
 * @property string|null $error_message
 * @property int $error_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ProductExport $productExport
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereErrorCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereProductExportId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereRowsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductExportRun whereUpdatedAt($value)
 *
 * @mixin \Eloquent
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

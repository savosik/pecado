<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Переход лида между стадиями.
 *
 * Пишется всегда, включая первое попадание в воронку: «сколько дней лид
 * на этапе» и «сколько этап занимает в среднем» задним числом
 * не восстанавливаются.
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $from_stage_id
 * @property int|null $to_stage_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $moved_at
 * @property int|null $previous_stage_hours
 */
class CrmLeadStageTransition extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'from_stage_id',
        'to_stage_id',
        'user_id',
        'moved_at',
        'previous_stage_hours',
    ];

    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
            'previous_stage_hours' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(CrmLeadStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(CrmLeadStage::class, 'to_stage_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Входящий сигнал пульта: что произошло и что движок с этим сделал.
 *
 * Отвечает на «почему клиенту ничего не пришло» — включая случай, когда не
 * совпало ни одно правило. Журнал писем на такое ответить не может: он
 * пишется по факту отправки.
 *
 * Prunable обязателен: строка пишется на каждое событие, а прецедент Pulse
 * (5,8 ГБ из 6,6 ГБ боевой базы) слишком свеж.
 */
class NotificationSignal extends Model
{
    use HasFactory, Prunable;

    protected $fillable = [
        'uuid',
        'event_key',
        'client_user_id',
        'company_id',
        'subject_type',
        'subject_id',
        'data',
        'tags',
        'view',
        'matched_rules_count',
        'deliveries_count',
        'dry_run',
        'mode',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'tags' => 'array',
            'view' => 'array',
            'dry_run' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'signal_uuid', 'uuid');
    }

    /**
     * Ретенция сигналов: короткая, это оперативная трасса.
     *
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function prunable()
    {
        $days = (int) config('notification_pulse.retention.signals_days', 30);

        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', now()->subDays($days));
    }
}

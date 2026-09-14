<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отложенные клиентом приглашения пройти опрос о налогах и НДС.
 *
 * Хранится на сервере, а не в браузере: клиент заходит и с телефона, и с
 * компьютера, и «Не сейчас» на одном не должно превращаться в повторную
 * просьбу на другом.
 *
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $snoozed_until
 * @property int $snooze_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $user
 */
class CrmTaxSurveyPrompt extends Model
{
    protected $fillable = [
        'user_id',
        'snoozed_until',
        'snooze_count',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'snooze_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'snoozed_until' => 'datetime',
            'snooze_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Можно ли сейчас показать приглашение.
     */
    public function allowsInvite(): bool
    {
        if ($this->snooze_count >= (int) config('crm_tax_regime.client_survey.max_snoozes')) {
            return false;
        }

        return $this->snoozed_until === null || $this->snoozed_until->isPast();
    }
}

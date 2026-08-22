<?php

namespace App\Models;

use App\Enums\Crm\CallDirection;
use App\Enums\Crm\CallResult;
use App\Models\Concerns\HasCrmAttachments;
use App\Models\Concerns\RecordsCrmSource;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

/**
 * Звонок менеджера: направление, итог разговора и договорённости.
 *
 * Пока заводится руками; поля `provider`, `external_id`, `duration_sec` и
 * `recording_url` — задел под интеграцию с АТС, чтобы её подключение не меняло
 * форму записи и фронт.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $client_user_id
 * @property string|null $related_type
 * @property int|null $related_id
 * @property CallDirection $direction
 * @property CallResult $result
 * @property string|null $phone
 * @property string|null $contact_name
 * @property string|null $summary
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property int|null $duration_sec
 * @property string|null $provider
 * @property string|null $external_id
 * @property string|null $recording_url
 * @property int|null $follow_up_task_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $author
 * @property-read User|null $client
 * @property-read CrmTask|null $followUpTask
 * @property-read Model|null $related
 * @property-read int|null $attachments_count
 */
class CrmCall extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\CrmCallFactory> */
    use HasCrmAttachments, HasFactory, RecordsCrmSource, SoftDeletes;

    /**
     * Источник записи, заведённой руками.
     */
    public const PROVIDER_MANUAL = 'manual';

    protected $fillable = [
        'user_id',
        'client_user_id',
        'related_type',
        'related_id',
        'direction',
        'result',
        'phone',
        'contact_id',
        'contact_name',
        'summary',
        'started_at',
        'duration_sec',
        'provider',
        'external_id',
        'recording_url',
        'follow_up_task_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'result' => CallResult::class,
            'started_at' => 'datetime',
            'duration_sec' => 'integer',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'direction' => 'outgoing',
        'result' => 'talked',
        'provider' => self::PROVIDER_MANUAL,
    ];

    /**
     * Инварианты держим в модели — так же, как у задачи.
     *
     * Звонок будет создаваться из диалога, из ленты и (когда появится АТС) из вебхука.
     * Если бы client_user_id заполнял вызывающий код, каждый новый путь мог бы его
     * забыть — и звонок молча выпал бы из ленты партнёра.
     */
    protected static function booted(): void
    {
        static::saving(function (self $call) {
            if ($call->client_user_id === null && $call->related_type !== null && $call->related instanceof Model) {
                $call->client_user_id = CrmEntityMap::clientIdFor($call->related);
            }

            // Время разговора — обязательная часть записи: звонок без момента
            // невозможно поставить в хронологию.
            if ($call->started_at === null) {
                $call->started_at = now();
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * Человек, с которым говорили. Свободное поле contact_name остаётся рядом:
     * звонить можно и тому, кого в справочнике нет.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Contact::class);
    }

    public function followUpTask(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class, 'follow_up_task_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_user_id', $clientId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * Длительность словами — «4 мин 20 с».
     */
    public function durationLabel(): ?string
    {
        if ($this->duration_sec === null) {
            return null;
        }

        $minutes = intdiv($this->duration_sec, 60);
        $seconds = $this->duration_sec % 60;

        return $minutes > 0 ? "{$minutes} мин {$seconds} с" : "{$seconds} с";
    }
}

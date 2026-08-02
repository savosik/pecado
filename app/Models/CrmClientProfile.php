<?php

namespace App\Models;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\ClientSentiment;
use App\Enums\Crm\PaymentBehavior;
use App\Enums\Crm\PreferredChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Профиль клиента в CRM — то, что менеджер знает о клиенте помимо данных 1С.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $decision_maker_name
 * @property string|null $decision_maker_role
 * @property string|null $decision_maker_contact
 * @property string|null $decision_process
 * @property PaymentBehavior|null $payment_behavior
 * @property string|null $payment_terms
 * @property int|null $order_cycle_days
 * @property PreferredChannel|null $preferred_channel
 * @property ClientSentiment|null $sentiment
 * @property string|null $notes_md
 * @property \Illuminate\Support\Carbon|null $notes_updated_at
 * @property int|null $notes_updated_by
 * @property ClientLifecycleStatus $lifecycle_status
 * @property \Illuminate\Support\Carbon|null $lifecycle_changed_at
 * @property int|null $lifecycle_changed_by
 * @property ClientLifecycleStatus|null $lifecycle_hint
 * @property string|null $lifecycle_hint_reason
 * @property \Illuminate\Support\Carbon|null $lifecycle_hint_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $client
 * @property-read User|null $notesEditor
 * @property-read User|null $lifecycleEditor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CrmClientProfileRevision> $revisions
 */
class CrmClientProfile extends Model
{
    /** @use HasFactory<\Database\Factories\CrmClientProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'decision_maker_name',
        'decision_maker_role',
        'decision_maker_contact',
        'decision_process',
        'payment_behavior',
        'payment_terms',
        'order_cycle_days',
        'preferred_channel',
        'sentiment',
        'notes_md',
        'notes_updated_at',
        'notes_updated_by',
        'lifecycle_status',
        'lifecycle_changed_at',
        'lifecycle_changed_by',
        'lifecycle_hint',
        'lifecycle_hint_reason',
        'lifecycle_hint_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_behavior' => PaymentBehavior::class,
            'preferred_channel' => PreferredChannel::class,
            'sentiment' => ClientSentiment::class,
            'notes_updated_at' => 'datetime',
            'order_cycle_days' => 'integer',
            'lifecycle_status' => ClientLifecycleStatus::class,
            'lifecycle_hint' => ClientLifecycleStatus::class,
            'lifecycle_changed_at' => 'datetime',
            'lifecycle_hint_at' => 'datetime',
        ];
    }

    /**
     * Значения по умолчанию для незаписанного профиля.
     *
     * Карточка клиента открывается и до первого сохранения, и статус там должен
     * читаться так же, как у сохранённого — иначе фронт получал бы null вместо enum.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lifecycle_status' => 'active',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function notesEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notes_updated_by');
    }

    public function lifecycleEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifecycle_changed_by');
    }

    /**
     * @return HasMany<CrmClientProfileRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CrmClientProfileRevision::class);
    }
}

<?php

namespace App\Models;

use App\Enums\Crm\EmailStatus;
use App\Enums\Crm\MailFolder;
use App\Models\Concerns\HasCrmAttachments;
use App\Models\Concerns\RecordsCrmSource;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;

/**
 * Письмо, отправленное менеджером партнёру из CRM.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $client_user_id
 * @property string|null $related_type
 * @property int|null $related_id
 * @property list<string> $to
 * @property list<string>|null $cc
 * @property string|null $reply_to
 * @property string $subject
 * @property string $body_html
 * @property EmailStatus $status
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property string|null $message_id
 * @property string $origin
 * @property string|null $origin_event
 * @property string|null $origin_key
 * @property array<string, mixed>|null $origin_data
 * @property array<int, string>|null $tags
 * @property int|null $auto_sent_rule_id
 * @property string|null $skip_reason
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $author
 * @property-read User|null $client
 * @property-read Model|null $related
 * @property-read int|null $attachments_count
 */
class CrmEmail extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\CrmEmailFactory> */
    use HasCrmAttachments, HasFactory, RecordsCrmSource;

    protected $fillable = [
        'user_id',
        'client_user_id',
        'origin',
        'origin_event',
        'origin_key',
        'origin_data',
        'tags',
        'related_type',
        'related_id',
        'to',
        'cc',
        'reply_to',
        'subject',
        'body_html',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'origin_data' => 'array',
            'tags' => 'array',
            'status' => EmailStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'origin' => 'manual',
    ];

    /** Письмо написал менеджер руками. */
    public const ORIGIN_MANUAL = 'manual';

    /** Письмо собрала система по поводу — заказ, документ, просрочка. */
    public const ORIGIN_SYSTEM = 'system';

    /**
     * Партнёр письма выводится из привязки — единой точкой на все пути создания,
     * как у комментариев и задач. Иначе письмо, отправленное по заказу, не попало бы
     * в ленту партнёра этого заказа.
     */
    protected static function booted(): void
    {
        static::saving(function (self $email) {
            if ($email->client_user_id === null && $email->related_type !== null && $email->related instanceof Model) {
                $email->client_user_id = CrmEntityMap::clientIdFor($email->related);
            }
        });
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
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
     * Правило, отправившее письмо само. Нужно в списке «Отправленных»:
     * менеджер должен видеть, что ушло без него и по какому фильтру.
     */
    public function autoSentRule(): BelongsTo
    {
        return $this->belongsTo(CrmMailRule::class, 'auto_sent_rule_id');
    }

    /**
     * Кому это письмо уже уходило. Слой, гарантирующий, что один адрес
     * не получит одно письмо дважды, сколько бы правил его ни поймало.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CrmEmailDelivery::class, 'crm_email_id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(CrmMailRuleHit::class, 'crm_email_id');
    }

    public function isSystem(): bool
    {
        return $this->origin === self::ORIGIN_SYSTEM;
    }

    /**
     * Метки письма — то, за что цепляются правила-фильтры.
     *
     * @return array<int, string>
     */
    public function tagList(): array
    {
        return array_values(array_filter(array_map(
            fn ($tag): string => (string) $tag,
            (array) ($this->tags ?? []),
        )));
    }

    /**
     * Письма одной папки.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInFolder(Builder $query, MailFolder $folder): Builder
    {
        return $query->whereIn('status', $folder->statuses());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForClient(Builder $query, int $clientUserId): Builder
    {
        return $query->where('client_user_id', $clientUserId);
    }
}

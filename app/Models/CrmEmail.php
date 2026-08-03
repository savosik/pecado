<?php

namespace App\Models;

use App\Enums\Crm\EmailStatus;
use App\Models\Concerns\HasCrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;

/**
 * Письмо, отправленное менеджером клиенту из CRM.
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
    use HasCrmAttachments, HasFactory;

    protected $fillable = [
        'user_id',
        'client_user_id',
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
            'status' => EmailStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Клиент письма выводится из привязки — единой точкой на все пути создания,
     * как у комментариев и задач. Иначе письмо, отправленное по заказу, не попало бы
     * в ленту клиента этого заказа.
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForClient(Builder $query, int $clientUserId): Builder
    {
        return $query->where('client_user_id', $clientUserId);
    }
}

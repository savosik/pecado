<?php

namespace App\Models;

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
 * Комментарий менеджера к клиенту, заказу или реализации.
 *
 * @property int $id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property int|null $client_user_id
 * @property int $user_id
 * @property string $body
 * @property bool $is_pinned
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read User $author
 * @property-read User|null $client
 * @property-read Model|null $commentable
 * @property-read int|null $attachments_count
 */
class CrmComment extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\CrmCommentFactory> */
    use HasCrmAttachments, HasFactory, RecordsCrmSource, SoftDeletes;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'client_user_id',
        'user_id',
        'body',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    /**
     * Резолв клиента — в одном месте на все пути создания.
     *
     * Комментарий приходит из интерфейса, из REST и (позже) от ИИ-агента. Если бы
     * client_user_id заполнял контроллер, каждый новый путь создания мог бы его забыть,
     * и комментарий молча выпал бы из ленты клиента.
     */
    protected static function booted(): void
    {
        static::creating(function (self $comment) {
            if ($comment->client_user_id !== null) {
                return;
            }

            $entity = $comment->commentable;

            if ($entity instanceof Model) {
                $comment->client_user_id = CrmEntityMap::clientIdFor($entity);
            }
        });
    }

    public function commentable(): MorphTo
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
     * Сквозная лента клиента: всё, что оставлено на нём самом, его заказах и отгрузках.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForClient(Builder $query, int $clientUserId): Builder
    {
        return $query->where('client_user_id', $clientUserId);
    }

    /**
     * Закреплённые — вверху, остальное в обратной хронологии.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id');
    }
}

<?php

namespace App\Models;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Роль человека при сущности: «бухгалтер контрагента Ромашка».
 *
 * Мягкого удаления здесь намеренно нет. Отвязать — значит удалить строку:
 * иначе уникальный индекс превращается в тыкву, потому что удалённая привязка
 * навсегда блокирует повторную. Мягко удаляется только человек — за ним тянутся
 * письма, звонки и задачи.
 *
 * `subject_type` хранит имя класса целиком, как `crm_comments.commentable_type`:
 * морф-карта в проекте не включена. Короткий ключ («contractor») живёт только
 * на границе HTTP и переводится через `CrmEntityMap`.
 *
 * @property int $id
 * @property int $contact_id
 * @property string $subject_type
 * @property int $subject_id
 * @property ContactRole $role
 * @property string|null $role_note
 * @property bool $is_primary
 * @property int|null $client_user_id
 * @property ContactSource $source
 */
class ContactLink extends Model
{
    /** @use HasFactory<\Database\Factories\ContactLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'subject_type',
        'subject_id',
        'role',
        'role_note',
        'is_primary',
        'client_user_id',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'role' => ContactRole::class,
            'source' => ContactSource::class,
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * Привязки к одной сущности.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRole(Builder $query, ContactRole $role): Builder
    {
        return $query->where('role', $role->value);
    }
}

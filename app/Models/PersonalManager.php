<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property string|null $erp_uuid
 * @property int|null $user_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @property-read \App\Models\User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalManager whereUserId($value)
 *
 * @mixin \Eloquent
 */
class PersonalManager extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'erp_uuid',
        'user_id',
        'name',
        'phone',
        'email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Карточки, которым место в списках и выборах CRM.
     *
     * 1С заводит карточку каждому, кого хоть раз указали менеджером в документе,
     * поэтому справочник обрастает уволившимися и техническими записями. Удалить
     * их нельзя — следующий обмен создаст их заново по erp_uuid, — поэтому
     * нерабочие карточки прячутся флагом.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('personal_managers.is_active', true);
    }

    /**
     * Клиенты, закреплённые за этим менеджером (users.personal_manager_id).
     *
     * Не путать с user() — это разные связи.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Аккаунт сотрудника, под которым менеджер входит в CRM (personal_managers.user_id).
     *
     * Не путать с users() — это разные связи.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Периоды отсутствия этого менеджера (отпуска, отгулы, больничные, прогулы).
     */
    public function absences(): HasMany
    {
        return $this->hasMany(ManagerAbsence::class, 'personal_manager_id');
    }

    /**
     * Отсутствия коллег, в которых этот менеджер назначен замещающим.
     */
    public function substitutions(): HasMany
    {
        return $this->hasMany(ManagerAbsence::class, 'substitute_manager_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            ])
            ->singleFile();
    }
}

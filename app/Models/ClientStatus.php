<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property string $name
 * @property string|null $color HEX цвет статуса, напр. #FFD700
 * @property string|null $description
 * @property numeric|null $amount_from Сумма от (для получения статуса)
 * @property string|null $external_id Внешний идентификатор
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 *
 * @method static \Database\Factories\ClientStatusFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus whereAmountFrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClientStatus whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ClientStatus extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'color',
        'description',
        'amount_from',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_from' => 'decimal:2',
        ];
    }

    /**
     * Пользователи с данным статусом клиента.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
            ])
            ->singleFile();
    }
}

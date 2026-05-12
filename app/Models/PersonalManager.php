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
 * @property string|null $phone
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
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
 *
 * @mixin \Eloquent
 */
class PersonalManager extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'phone',
        'email',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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

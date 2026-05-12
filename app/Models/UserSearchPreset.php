<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $section
 * @property string $name
 * @property array<array-key, mixed> $filters
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset whereFilters($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset whereSection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearchPreset whereUserId($value)
 *
 * @mixin \Eloquent
 */
class UserSearchPreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'section',
        'name',
        'filters',
    ];

    protected $casts = [
        'filters' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

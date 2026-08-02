<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала смен статусов клиента.
 *
 * @property int $id
 * @property int $client_user_id
 * @property string $field
 * @property string|null $from_value
 * @property string $to_value
 * @property int|null $user_id
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $client
 * @property-read User|null $author
 */
class CrmClientStatusChange extends Model
{
    /** Жизненный статус клиента — пока единственное, что журналируется. */
    public const FIELD_LIFECYCLE = 'lifecycle';

    protected $fillable = [
        'client_user_id',
        'field',
        'from_value',
        'to_value',
        'user_id',
        'reason',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

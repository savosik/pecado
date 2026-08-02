<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Предыдущая версия свободных заметок о клиенте.
 *
 * @property int $id
 * @property int $crm_client_profile_id
 * @property int|null $user_id
 * @property string|null $notes_md
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CrmClientProfile $profile
 * @property-read User|null $author
 */
class CrmClientProfileRevision extends Model
{
    protected $fillable = [
        'crm_client_profile_id',
        'user_id',
        'notes_md',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CrmClientProfile::class, 'crm_client_profile_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

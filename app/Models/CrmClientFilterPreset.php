<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Личный отбор списка клиентов.
 *
 * Хранит снимок фильтров рабочего списка ({@see \App\Support\Crm\ClientListFilters}),
 * чтобы менеджер возвращался к своей выборке одним кликом, а не пересобирал её
 * каждое утро. Отбор личный: чужой не читается и не удаляется — доступ гейтится
 * связью, а не политикой (см. ClientController::destroyPreset()).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $payload
 */
class CrmClientFilterPreset extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

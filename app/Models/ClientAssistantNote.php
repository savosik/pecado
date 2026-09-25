<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заметка помощника о клиенте — память между тредами.
 *
 * Несколько килобайт свободного текста: как обращаться, что и как часто
 * заказывает, предпочтения по доставке, что уже обсуждали. Дописывает модель
 * по закрытии треда; сотрудник может поправить в CRM. Уходит в системную
 * часть запроса под маркером кеша.
 *
 * @property int $id
 * @property int $user_id
 * @property string $content
 * @property int $version
 * @property string|null $updated_by
 * @property-read User $user
 */
class ClientAssistantNote extends Model
{
    public const BY_MODEL = 'model';

    public const BY_STAFF = 'staff';

    /** Потолок заметки: длиннее — платим за неё каждым ходом. */
    public const MAX_LENGTH = 6000;

    protected $fillable = [
        'user_id',
        'content',
        'version',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

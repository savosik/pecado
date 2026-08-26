<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Настройка уведомления у партнёра.
 *
 * Строка существует **только когда настройка отличается от умолчания**.
 * Возврат к умолчанию — это удаление строки, а не запись копии умолчания:
 * иначе изменение умолчания в конфиге не дошло бы до тех, кто его не менял.
 *
 * @property int $user_id
 * @property string $occasion_key
 * @property bool $is_enabled
 * @property array<int, array<string, mixed>>|null $destinations
 * @property array<string, mixed>|null $options
 * @property bool $changed_by_client
 */
class NotificationPreference extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'occasion_key',
        'is_enabled',
        'destinations',
        'options',
        'changed_by_client',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'destinations' => 'array',
            'options' => 'array',
            'changed_by_client' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

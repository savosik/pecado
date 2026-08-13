<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Подписка на изменения сущностей раздела личного кабинета.
 *
 * Универсальная модель: одна подписка = (пользователь-владелец, раздел,
 * канал, адресат). Раздел — строковый ключ из App\Services\Subscriptions\
 * SubscriptionRegistry ('orders', позже 'shipments' и т.д.), канал —
 * 'email' (сейчас) или 'telegram' (задел на будущее).
 *
 * @property int $id
 * @property int $user_id
 * @property string $section
 * @property string $channel
 * @property string $destination
 * @property array<int, string>|null $events
 * @property bool $is_active
 * @property string $unsubscribe_token
 * @property \Illuminate\Support\Carbon|null $last_notified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 */
class EntitySubscription extends Model
{
    protected $fillable = [
        'user_id',
        'section',
        'channel',
        'destination',
        'events',
        'is_active',
        'unsubscribe_token',
        'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_notified_at' => 'datetime',
        ];
    }

    /**
     * Подписан ли адресат на данный тип события раздела.
     *
     * Пустой список событий = «все типы» (в т.ч. те, что появятся позже), как
     * и у подписок, созданных до появления градации по типам.
     */
    public function wantsEvent(?string $event): bool
    {
        if ($event === null || blank($this->events)) {
            return true;
        }

        return in_array($event, $this->events, true);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $subscription) {
            if (empty($subscription->unsubscribe_token)) {
                $subscription->unsubscribe_token = Str::random(64);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

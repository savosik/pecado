<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Пользователь сменил свой пароль (через сброс по email или в личном кабинете).
 *
 * Используется для отправки уведомления безопасности «ваш пароль изменён».
 * Не покрывает программные изменения пароля (миграции, ERP-импорты) — там
 * event диспатчить не нужно.
 */
class UserPasswordChanged
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $source  'reset' (через email-токен) или 'cabinet' (в личном кабинете)
     * @param  ?string  $ip  IP-адрес, с которого инициирована смена
     */
    public function __construct(
        public User $user,
        public string $source = 'cabinet',
        public ?string $ip = null,
    ) {}
}

<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Пользователь сам зарегистрировался на сайте Pecado.ru (через форму или соцсеть)
 * либо менеджер явно попросил отправить ему welcome-письмо при создании из админки.
 *
 * Отдельно от Eloquent-события User::created — оно стреляет и для контрагентов
 * из 1С (HandlePartnerCreated), которым welcome не нужен.
 */
class UserRegisteredOnSite
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public string $source = 'web',
    ) {}
}

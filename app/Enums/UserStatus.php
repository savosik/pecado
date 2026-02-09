<?php

namespace App\Enums;

enum UserStatus: string
{
    case PROCESSING = 'processing';
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::PROCESSING => 'На рассмотрении',
            self::ACTIVE => 'Активен',
            self::BLOCKED => 'Заблокирован',
        };
    }
}

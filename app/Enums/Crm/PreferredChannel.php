<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Как клиенту удобнее общаться. Нужно и менеджеру, и ИИ-агенту:
 * письмо тому, кто читает только Telegram, останется без ответа.
 */
enum PreferredChannel: string
{
    use HasLabeledOptions;

    case PHONE = 'phone';
    case EMAIL = 'email';
    case WHATSAPP = 'whatsapp';
    case TELEGRAM = 'telegram';
    case PERSONAL = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::PHONE => 'Телефон',
            self::EMAIL => 'Почта',
            self::WHATSAPP => 'WhatsApp',
            self::TELEGRAM => 'Telegram',
            self::PERSONAL => 'Личные встречи',
        };
    }
}

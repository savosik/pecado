<?php

namespace App\Enums;

/**
 * Жизненный цикл вопроса от пользователя на странице FAQ.
 *
 * new → in_progress → answered (или rejected).
 * Двусторонней переписки нет — после answered или rejected обращение закрыто.
 */
enum UserQuestionStatus: string
{
    case NEW = 'new';
    case IN_PROGRESS = 'in_progress';
    case ANSWERED = 'answered';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Новый',
            self::IN_PROGRESS => 'В работе',
            self::ANSWERED => 'Отвечен',
            self::REJECTED => 'Отклонён',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NEW => 'blue',
            self::IN_PROGRESS => 'yellow',
            self::ANSWERED => 'green',
            self::REJECTED => 'red',
        };
    }
}

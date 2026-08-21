<?php

namespace App\Notifications\Pulse\Events\System;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Вопрос с сайта.
 *
 * У этого события нет владельца в данных, если спрашивал гость: адресат
 * задаётся списком из настроек, а не персональным менеджером.
 */
class QuestionReceivedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'system.question_received';
    }

    public function label(): string
    {
        return 'Вопрос с сайта';
    }

    public function description(): string
    {
        return 'Посетитель задал вопрос через форму на сайте';
    }

    public function fields(): array
    {
        return [
            'is_guest' => new FieldSpec('is_guest', 'Спрашивал гость', FieldSpec::TYPE_BOOL,
                hint: 'У гостя нет персонального менеджера — письмо уходит на общий адрес'),
            'question_id' => new FieldSpec('question_id', 'Номер вопроса', FieldSpec::TYPE_NUMBER),
        ];
    }

    public function defaultSubject(): string
    {
        return 'Новый вопрос с сайта';
    }
}

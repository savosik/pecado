<?php

namespace App\Notifications\Pulse\Events\Finance;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Просрочка выросла: сумма увеличилась или пересечена очередная ступень.
 *
 * «Совсем плохая ситуация» из постановки заказчика — это условие на
 * пересечённую ступень или на сумму, а не отдельное событие: порог задаётся
 * в правиле, чтобы его можно было менять без релиза.
 */
class OverdueGrewEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'finance.overdue_grew';
    }

    public function label(): string
    {
        return 'Просрочка выросла';
    }

    public function description(): string
    {
        return 'Сумма просрочки увеличилась или пройдена очередная ступень: 30, 60, 90 дней';
    }

    public function fields(): array
    {
        return array_merge(OverdueStartedEvent::overdueFields(), [
            'previous_days_overdue' => new FieldSpec('previous_days_overdue', 'Было дней просрочки', FieldSpec::TYPE_NUMBER),
            'previous_amount' => new FieldSpec('previous_amount', 'Была сумма просрочки', FieldSpec::TYPE_MONEY),
            'crossed_step' => new FieldSpec('crossed_step', 'Пройденная ступень, дней', FieldSpec::TYPE_ENUM, [
                ['value' => '30', 'label' => '30 дней'],
                ['value' => '60', 'label' => '60 дней'],
                ['value' => '90', 'label' => '90 дней'],
            ], hint: 'Заполняется, когда просрочка перевалила за очередной порог'),
        ]);
    }

    protected function ownTags(array $data): array
    {
        $tags = OverdueStartedEvent::overdueTags($data);

        if (filled($data['crossed_step'] ?? null)) {
            $tags[] = 'просрочка:пересечён-порог-'.$data['crossed_step'];
        }

        return $tags;
    }

    public function defaultTemplate(): string
    {
        return 'mail.pulse.finance.overdue';
    }

    public function defaultSubject(): string
    {
        return 'Просроченная задолженность выросла — Pecado.ru';
    }
}

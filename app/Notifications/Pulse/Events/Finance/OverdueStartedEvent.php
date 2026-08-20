<?php

namespace App\Notifications\Pulse\Events\Finance;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Просрочка появилась там, где вчера её не было.
 *
 * Просрочка — состояние, а не событие: она длится месяцами. Поэтому события
 * порождаются на переходах, иначе клиент получал бы письмо каждый день,
 * пока не заплатит, и перестал бы их читать.
 */
class OverdueStartedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'finance.overdue_started';
    }

    public function label(): string
    {
        return 'Возникла просрочка';
    }

    public function description(): string
    {
        return 'У контрагента появилась просроченная задолженность';
    }

    public function fields(): array
    {
        return self::overdueFields();
    }

    protected function ownTags(array $data): array
    {
        return self::overdueTags($data);
    }

    public function defaultTemplate(): string
    {
        return 'mail.pulse.finance.overdue';
    }

    public function defaultSubject(): string
    {
        return 'Просроченная задолженность — Pecado.ru';
    }

    /**
     * Поля просрочки общие для «возникла» и «выросла».
     *
     * @return array<string, FieldSpec>
     */
    public static function overdueFields(): array
    {
        return [
            'days_overdue' => new FieldSpec('days_overdue', 'Дней просрочки', FieldSpec::TYPE_NUMBER),
            'overdue_amount' => new FieldSpec('overdue_amount', 'Сумма просрочки', FieldSpec::TYPE_MONEY),
            'total_debt' => new FieldSpec('total_debt', 'Общий долг', FieldSpec::TYPE_MONEY),
            'oldest_due_date' => new FieldSpec('oldest_due_date', 'Самый ранний просроченный платёж', FieldSpec::TYPE_DATE),
            'positions_count' => new FieldSpec('positions_count', 'Просроченных документов', FieldSpec::TYPE_NUMBER),
        ];
    }

    /**
     * Ступени просрочки метками: простое условие «содержит» для типовых
     * случаев. Кому нужен свой порог — пользуется числовым сравнением.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function overdueTags(array $data): array
    {
        $days = (int) ($data['days_overdue'] ?? 0);
        $tags = ['оплата:просрочка'];

        foreach ([90, 60, 30] as $step) {
            if ($days >= $step) {
                $tags[] = 'просрочка:'.$step.'+';
            }
        }

        return $tags;
    }
}

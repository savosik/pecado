<?php

namespace App\Console\Commands;

use App\Services\Crm\CrmTaskRecurrenceService;
use Illuminate\Console\Command;

/**
 * Порождение задач по расписанию (crm-29).
 *
 * Запускается планировщиком раз в сутки рядом с напоминаниями по задачам.
 * Повторный запуск безопасен: вхождения защищены уникальным ключом
 * `(recurrence_id, occurrence_date)`, поэтому ручной прогон при отладке
 * не задвоит поручения.
 */
class CrmTasksRecur extends Command
{
    protected $signature = 'crm:tasks-recur
        {--horizon= : На сколько дней вперёд материализовать задачи}
        {--date= : Дата, от которой считать (для отладки и досоздания пропущенного)}';

    protected $description = 'Создать задачи по активным правилам автоповтора';

    public function handle(CrmTaskRecurrenceService $recurrences): int
    {
        $horizon = $this->option('horizon') !== null
            ? max(0, (int) $this->option('horizon'))
            : CrmTaskRecurrenceService::HORIZON_DAYS;

        $from = $this->option('date') !== null
            ? \Carbon\CarbonImmutable::parse((string) $this->option('date'))
            : null;

        $created = $recurrences->generate($from, $horizon);

        $this->info($created === 0
            ? 'Новых задач по расписанию нет.'
            : "Создано задач по расписанию: {$created}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands\Motivation;

use App\Services\Motivation\PartnerAssignmentBackfiller;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Первичное заполнение реестра закрепления сегодняшним состоянием.
 *
 * Выполняется один раз при вводе системы. Повторный запуск безопасен: партнёры
 * с действующей записью пропускаются, поэтому команда доводит реестр, а не
 * задваивает его.
 */
class BackfillAssignments extends Command
{
    protected $signature = 'motivation:backfill-assignments {--starts-on=2026-01-01 : С какой даты действует закрепление}';

    protected $description = 'Заполнить реестр закрепления партнёров текущим состоянием (разово, при вводе системы)';

    public function handle(PartnerAssignmentBackfiller $backfiller): int
    {
        $startsOn = CarbonImmutable::parse((string) $this->option('starts-on'));

        $result = $backfiller->run($startsOn);

        $this->info(sprintf('Заведено записей: %d', $result['created']));

        if ($result['skipped'] > 0) {
            $this->line(sprintf('Пропущено — действующая запись уже есть: %d', $result['skipped']));
        }

        $this->newLine();
        $this->warn('Записи бэкфилла переносят сегодняшнюю картину на всю доступную историю.');
        $this->line('Для месяцев до перераспределения партнёров это не знание, а его отсутствие:');
        $this->line('такие строки помечены основанием «initial» и отличимы от настоящих переводов.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands\Motivation;

use App\Models\User;
use App\Services\Motivation\GuaranteeBaseService;
use App\Services\Motivation\ParallelCalculationService;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Фиксация базы гарантии переходного периода (п. 12.3) — разово, при вводе Положения.
 *
 * Повторный запуск безопасен: у кого база уже зафиксирована, тот пропускается;
 * пересчитать заново можно только явным --overwrite.
 */
class FixGuaranteeBase extends Command
{
    protected $signature = 'motivation:fix-guarantee-base
        {--effective-from= : Месяц введения Положения (ГГГГ-ММ); по умолчанию — из схемы 2.2}
        {--overwrite : Перезаписать уже зафиксированные базы}
        {--user= : От чьего имени (users.id); по умолчанию — первый суперадмин}';

    protected $description = 'Зафиксировать базу гарантии переходного периода: среднее за три месяца до введения Положения';

    public function handle(GuaranteeBaseService $service, ParallelCalculationService $parallel): int
    {
        $raw = (string) $this->option('effective-from');
        if ($raw !== '') {
            $effective = CarbonImmutable::parse(substr($raw, 0, 7).'-01');
        } else {
            $window = $parallel->window();
            if ($window === null) {
                $this->error('Схема 2.2 не введена: укажите --effective-from=ГГГГ-ММ.');

                return self::FAILURE;
            }
            $effective = $window['effective_from'];
        }

        $userId = (int) $this->option('user');
        $actor = $userId > 0 ? User::query()->find($userId) : User::role('super-admin')->orderBy('id')->first();
        if ($actor === null) {
            $this->error('Не найден пользователь, от чьего имени фиксировать базу.');

            return self::FAILURE;
        }

        $result = $service->fix($effective, $actor, (bool) $this->option('overwrite'));

        $this->info(sprintf('Положение действует с %s; гарантия — до %s (не включая).', $result['effective_from'], $result['until']));
        $this->table(
            ['Работник', 'Месяцы', 'Среднее', 'Минимум', 'Статус'],
            array_map(fn (array $row): array => [
                $row['manager']['name'],
                implode(', ', array_map(fn (array $m): string => substr($m['month'], 0, 7).' '.Money::rub($m['total']).' ('.$m['status'].')', $row['months'])),
                Money::rub($row['average']),
                Money::rub($row['minimum']),
                $row['skipped'] ? 'пропущен — база уже '.Money::rub((float) $row['previous_base']) : 'зафиксировано',
            ], $result['rows']),
        );

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\Crm;

use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Табель отдела продаж: месячная сетка по менеджерам (эпик abs-00).
 *
 * Отдельной таблицы дневных отметок нет намеренно: «работал» — это отсутствие
 * записи об отсутствии в рабочий день, поэтому табель, замещение в кабинете
 * и роутинг писем читают одни и те же строки `manager_absences` и разойтись
 * не могут. Выходные и праздники — из `config/production_calendar.php`.
 */
class TimesheetService
{
    /** Код клетки: рабочий день без отсутствия. */
    private const CODE_WORK = 'Я';

    /** Код клетки: выходной или праздник. */
    private const CODE_WEEKEND = 'В';

    private const DOW_LABELS = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];

    /**
     * Сетка табеля за месяц.
     *
     * Будущие дни без запланированного отсутствия остаются пустыми: «Я» —
     * констатация факта, а не прогноз. Запланированный отпуск в будущих днях
     * показывается сразу. Отсутствие в выходной показывается как выходной:
     * спорные случаи руководитель правит периодом записи.
     *
     * @return array{month: string, month_label: string, days: list<array<string, mixed>>, rows: list<array<string, mixed>>, legend: list<array{code: string, label: string}>}
     */
    public function forMonth(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $today = CarbonImmutable::today();

        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->day,
                'dow_label' => self::DOW_LABELS[$date->dayOfWeekIso],
                'is_weekend' => ! $this->isWorkingDay($date),
            ];
        }

        $managers = PersonalManager::query()
            ->active()
            ->whereNotNull('user_id')
            ->orderBy('name')
            ->get(['id', 'name']);

        $absences = ManagerAbsence::query()
            ->whereIn('personal_manager_id', $managers->pluck('id'))
            ->overlapping($start, $end)
            ->get()
            ->groupBy('personal_manager_id');

        $rows = $managers->map(function (PersonalManager $manager) use ($days, $absences, $today): array {
            $own = $absences->get($manager->id) ?? collect();
            $cells = [];
            $totals = ['work' => 0, 'vacation' => 0, 'day_off' => 0, 'sick_leave' => 0, 'truancy' => 0];

            foreach ($days as $day) {
                $date = CarbonImmutable::parse($day['date']);
                $absence = $own->first(fn (ManagerAbsence $a): bool => $a->starts_on->lte($date) && $a->ends_on->gte($date));

                if ($day['is_weekend']) {
                    $code = self::CODE_WEEKEND;
                } elseif ($absence) {
                    $code = $absence->type->timesheetCode();
                    $totals[$absence->type->value]++;
                } elseif ($date->lte($today)) {
                    $code = self::CODE_WORK;
                    $totals['work']++;
                } else {
                    $code = '';
                }

                $cells[] = [
                    'date' => $day['date'],
                    'code' => $code,
                    'absence_id' => (! $day['is_weekend'] && $absence) ? $absence->id : null,
                ];
            }

            return [
                'manager' => ['id' => $manager->id, 'name' => $manager->name],
                'cells' => $cells,
                'totals' => $totals,
            ];
        })->all();

        return [
            'month' => $month->format('Y-m'),
            'month_label' => \Illuminate\Support\Str::ucfirst($month->locale('ru')->isoFormat('MMMM YYYY')),
            'days' => $days,
            'rows' => $rows,
            'legend' => [
                ['code' => self::CODE_WORK, 'label' => 'работал'],
                ['code' => self::CODE_WEEKEND, 'label' => 'выходной'],
                ['code' => 'ОТ', 'label' => 'отпуск'],
                ['code' => 'ОД', 'label' => 'отгул'],
                ['code' => 'Б', 'label' => 'больничный'],
                ['code' => 'ПР', 'label' => 'прогул'],
            ],
        ];
    }

    /**
     * Рабочий ли день по производственному календарю.
     */
    public function isWorkingDay(CarbonInterface $date): bool
    {
        $year = $date->year;
        $key = $date->format('Y-m-d');

        if (in_array($key, config("production_calendar.holidays.{$year}", []), true)) {
            return false;
        }

        if ($date->isWeekend()) {
            return in_array($key, config("production_calendar.working_weekends.{$year}", []), true);
        }

        return true;
    }
}

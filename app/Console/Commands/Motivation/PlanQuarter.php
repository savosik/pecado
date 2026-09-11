<?php

namespace App\Console\Commands\Motivation;

use App\Models\PersonalManager;
use App\Services\Motivation\PlanCalculator;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Расчёт планов на квартал по формуле Положения — без сохранения.
 *
 * Показывает рядом расчётное значение и действующий план: они поставлены
 * по разным методикам и расходятся до полутора раз, и руководитель обязан
 * увидеть разницу до утверждения, а не после.
 */
class PlanQuarter extends Command
{
    protected $signature = 'motivation:plan-quarter
        {quarter? : Первый месяц квартала Y-m; по умолчанию следующий}
        {--manager=* : id карточек менеджеров; по умолчанию все с расчётом зарплаты}';

    protected $description = 'Посчитать личные планы на квартал по формуле Положения и сравнить с действующими';

    public function handle(PlanCalculator $calculator): int
    {
        $quarter = $this->argument('quarter')
            ? CarbonImmutable::parse((string) $this->argument('quarter').'-01')->startOfQuarter()
            : CarbonImmutable::now()->addQuarter()->startOfQuarter();

        $managerIds = array_map('intval', (array) $this->option('manager'));

        if ($managerIds === []) {
            $managerIds = PersonalManager::query()
                ->where('payroll_enabled', true)
                ->pluck('id')
                ->map('intval')
                ->all();
        }

        if ($managerIds === []) {
            $this->error('Не найдено ни одного менеджера с расчётом зарплаты.');

            return self::FAILURE;
        }

        $this->info(sprintf('Планы на квартал с %s', $quarter->format('m.Y')));

        foreach ($managerIds as $managerId) {
            $manager = PersonalManager::query()->find($managerId);

            if ($manager === null) {
                $this->warn(sprintf('Менеджер #%d не найден — пропущен.', $managerId));

                continue;
            }

            $result = $calculator->calculate($managerId, $quarter);
            $total = array_sum($result['values']);
            $previousSum = array_sum(array_map('floatval', array_filter($result['previous_values'], fn ($v) => $v !== null)));

            $this->newLine();
            $this->line(sprintf('<options=bold>%s</>', $manager->name));
            $this->line(sprintf(
                'Медиана %s за отработанный рабочий день · выборка %s — %s: дней %d, из них без отгрузок %d, исключено по табелю %d',
                Money::rub($result['median_per_day']),
                CarbonImmutable::parse($result['sample']['from'])->format('d.m.Y'),
                CarbonImmutable::parse($result['sample']['to'])->format('d.m.Y'),
                $result['sample']['days'],
                $result['sample']['zero_days'],
                $result['sample']['excluded_days'],
            ));

            if ($result['sample']['days'] > 0 && $result['sample']['zero_days'] / $result['sample']['days'] > 0.25) {
                $this->warn('Больше четверти дней выборки без отгрузок — проверьте полноту данных периода: за половиной медиана обращается в ноль.');
            }

            if (! $result['previous_quarter_comparable'] && $result['previous_quarter_total'] !== null) {
                $this->line('Предел снижения не применяется: план прошлого квартала поставлен не по этой методике.');
            }

            if ($result['overperformance_carry'] > 0) {
                $this->line(sprintf('Учтено перевыполнение прошлого квартала: %s', Money::rub($result['overperformance_carry'])));
            }

            if ($result['decline_limited']) {
                $this->warn(sprintf(
                    'Применён предел снижения: расчётный план был ниже плана прошлого квартала (%s) более чем на допустимую долю',
                    Money::rub((float) $result['previous_quarter_total']),
                ));
            }

            $rows = [];
            foreach ($result['values'] as $month => $value) {
                $previous = $result['previous_values'][$month] ?? null;
                $rows[] = [
                    CarbonImmutable::parse((string) $month)->format('m.Y'),
                    (string) $result['working_days'][$month],
                    Money::factor($result['seasonal'][$month]),
                    Money::rub($value),
                    $previous === null ? '—' : Money::rub($previous),
                    $previous === null || $previous == 0
                        ? '—'
                        : sprintf('%+d %%', (int) round(($value / $previous - 1) * 100)),
                ];
            }

            $this->table(['Месяц', 'Раб. дней', 'Сезон', 'Расчётный план', 'Действующий', 'Разница'], $rows);
            $this->line(sprintf(
                'Итого за квартал: %s против действующих %s',
                Money::rub($total),
                $previousSum > 0 ? Money::rub($previousSum) : '—',
            ));
        }

        return self::SUCCESS;
    }
}

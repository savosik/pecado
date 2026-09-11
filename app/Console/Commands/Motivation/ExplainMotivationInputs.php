<?php

namespace App\Console\Commands\Motivation;

use App\Models\PersonalManager;
use App\Services\Motivation\MotivationInputCollector;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * «Из чего сложились показатели переменной части» — разбор входов без расчёта.
 *
 * Нужна затем, что спор о переменной части почти всегда оказывается спором
 * не о формуле, а о входах: какая отгрузка попала в базу, а какая в новизну,
 * за какие дни начислен вычет. Разбор по документам отвечает на это без SQL.
 *
 * Ею же сверяется расчёт с теневым: команду можно выполнить на dev и на бою
 * и сравнить числа с разделом 03a технического задания.
 */
class ExplainMotivationInputs extends Command
{
    protected $signature = 'motivation:explain-inputs
        {manager : id карточки менеджера}
        {month? : Месяц Y-m, по умолчанию текущий}
        {--debts=15 : Сколько документов задолженности показать}
        {--grace= : Льготный период в рабочих днях; по умолчанию из приказа}';

    protected $description = 'Разобрать входы переменной части менеджера за месяц: группы отгрузок, фокус-перечень, база вычета, табель';

    public function handle(MotivationInputCollector $collector): int
    {
        $manager = PersonalManager::query()->find((int) $this->argument('manager'));

        if ($manager === null) {
            $this->error('Менеджер не найден.');

            return self::FAILURE;
        }

        $month = $this->argument('month')
            ? CarbonImmutable::parse((string) $this->argument('month').'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $params = $this->option('grace') === null
            ? []
            : ['grace_working_days' => (int) $this->option('grace')];

        $inputs = $collector->collect((int) $manager->getKey(), $month, $params);
        $rate = (float) config('motivation.default_parameters.rate_k1_per_day', 0.0005);

        $this->line(sprintf('%s — %s', $manager->name, MonthLabel::ru($month)));
        $this->newLine();

        $this->table(['Показатель', 'Значение', 'Пояснение'], [
            ['Отгрузки закреплённой базы', Money::rub($inputs->baseRevenue), sprintf('партнёров с отгрузками: %d', count($inputs->baseRows))],
            ['Отгрузки новым партнёрам', Money::rub($inputs->newPartnersRevenue), sprintf('партнёров в периоде новизны: %d', $inputs->newPartnersCount)],
            ['Отгрузки фокус-перечня', Money::rub($inputs->focusRevenue), sprintf('позиций: %d', count($inputs->focusRows))],
            ['База вычета К1', sprintf('%s · дней', Money::rub($inputs->overdueIntegral)), sprintf('вычет по ставке %s: %s', Money::percent($rate, 3), Money::rub($inputs->overdueIntegral * $rate))],
            ['Возвраты периода', Money::rub($inputs->returns['base'] + $inputs->returns['new']), 'уже вычтены из групп'],
            ['Рабочие дни', sprintf('%d из %d', $inputs->workedDays, $inputs->workingDaysTotal), $inputs->hasAbsence() ? 'план уменьшается (п. 10.1)' : 'отсутствий не было'],
            ['Дни замещения', (string) $inputs->substitutionDays, 'надбавка по п. 4.3'],
        ]);

        if ($inputs->focusRows !== []) {
            $this->newLine();
            $this->line('Фокус-перечень:');
            $this->table(['Позиция', 'Отгружено', 'Ставка'], array_map(
                fn (array $row): array => [
                    (string) ($row['name'] ?? ''),
                    Money::rub($row['amount'] ?? 0),
                    isset($row['rate']) ? Money::percent((float) $row['rate'], 2) : 'общая',
                ],
                array_slice($inputs->focusRows, 0, 15),
            ));
        }

        $limit = max(0, (int) $this->option('debts'));

        if ($inputs->overdueRows !== [] && $limit > 0) {
            $this->newLine();
            $this->line(sprintf('Задолженность — %d документов, показаны крупнейшие:', count($inputs->overdueRows)));
            $this->table(['Накладная', 'Партнёр', 'Сумма', 'Срок', 'Закрыт', 'Дней', 'Вклад в базу'], array_map(
                fn (array $row): array => [
                    (string) ($row['number'] ?? ''),
                    mb_strimwidth((string) ($row['partner_name'] ?? ''), 0, 28, '…'),
                    Money::rub($row['amount'] ?? 0),
                    (string) ($row['due_on'] ?? ''),
                    (string) ($row['settled_on'] ?? 'не закрыт'),
                    (string) ($row['days'] ?? 0),
                    Money::rub($row['integral'] ?? 0),
                ],
                array_slice($inputs->overdueRows, 0, $limit),
            ));
        }

        return self::SUCCESS;
    }
}

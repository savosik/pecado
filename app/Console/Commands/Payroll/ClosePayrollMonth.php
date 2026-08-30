<?php

namespace App\Console\Commands\Payroll;

use App\Models\PersonalManager;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Закрытие месяца: финальный пересчёт черновиков прошлого месяца перед утверждением.
 *
 * Утверждение остаётся ручным (решение заказчика): команда лишь приводит
 * черновики к свежим данным первого числа и печатает сводку, чтобы РОП
 * открыл «Зарплату отдела» с готовыми цифрами.
 */
class ClosePayrollMonth extends Command
{
    protected $signature = 'payroll:close-month {month? : Месяц Y-m; по умолчанию — прошлый}';

    protected $description = 'Финальный пересчёт черновиков зарплаты за прошлый месяц перед утверждением';

    public function handle(PayrollCalculationService $service): int
    {
        $month = $this->argument('month')
            ? CarbonImmutable::parse((string) $this->argument('month').'-01')->startOfMonth()
            : CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();

        $rows = [];
        $sum = 0.0;

        $managers = PersonalManager::query()->active()->inPayroll()->whereNotNull('user_id')->orderBy('name')->get(['id', 'name']);

        foreach ($managers as $manager) {
            $calculation = $service->recalculateDraft((int) $manager->getKey(), $month, 'close-month')
                ?? $service->current((int) $manager->getKey(), $month);

            if ($calculation === null) {
                continue;
            }

            $sum += (float) $calculation->total;
            $rows[] = [(string) $manager->name, Money::rub((float) $calculation->total), $calculation->statusLabel()];
        }

        $this->info(sprintf('%s: %d менедж., ФОТ %s', MonthLabel::ru($month), count($rows), Money::rub($sum)));
        $this->table(['Менеджер', 'Итого', 'Статус'], $rows);

        return self::SUCCESS;
    }
}

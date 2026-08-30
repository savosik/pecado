<?php

namespace App\Console\Commands\Payroll;

use App\Models\PersonalManager;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * «Почему у менеджера такая зарплата» — разбор по компонентам, без сохранения.
 */
class ExplainPayroll extends Command
{
    protected $signature = 'payroll:explain {manager : id карточки менеджера} {month? : Месяц Y-m, по умолчанию текущий}';

    protected $description = 'Разобрать расчёт зарплаты менеджера за месяц по компонентам';

    public function handle(PayrollCalculationService $service): int
    {
        $manager = PersonalManager::query()->find((int) $this->argument('manager'));

        if ($manager === null) {
            $this->error('Менеджер не найден.');

            return self::FAILURE;
        }

        $month = $this->argument('month')
            ? CarbonImmutable::parse((string) $this->argument('month').'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $preview = $service->preview((int) $manager->getKey(), $month);
        $breakdown = $preview['breakdown'];
        $inputs = $preview['inputs'];

        $this->line(sprintf('%s — %s', $manager->name, MonthLabel::ru($month)));
        $this->line(sprintf(
            'План %s, реализации %s, плановых клиентов %d (активных %d), накладных со штрафом %d, рабочих дней %d/%d',
            $inputs->plan === null ? '—' : Money::rub($inputs->plan),
            Money::rub($inputs->revenue),
            count($inputs->plannedClients),
            count($inputs->activeClients()),
            count($inputs->invoices),
            $inputs->workingDays['passed'],
            $inputs->workingDays['total'],
        ));
        $this->newLine();

        foreach ($breakdown->components as $component) {
            $this->line(sprintf('<info>%s</info>: %s', $component->label, Money::rub($component->amount ?? 0)));
            $this->line('  '.$component->explanation);

            foreach ($component->children as $child) {
                $this->line(sprintf(
                    '  • %s%s',
                    $child->explanation,
                    $child->effectRub === null ? '' : sprintf(' [эффект %s]', Money::rub($child->effectRub)),
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf('<comment>Итого: %s</comment>', Money::rub($breakdown->total)));

        foreach ($breakdown->warnings as $warning) {
            $this->warn('! '.$warning);
        }

        return self::SUCCESS;
    }
}

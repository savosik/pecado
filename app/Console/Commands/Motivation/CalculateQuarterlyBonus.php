<?php

namespace App\Console\Commands\Motivation;

use App\Models\Motivation\MotivationQuarterlyBonus;
use App\Models\Motivation\MotivationQuarterlyQualification;
use App\Services\Motivation\QuarterlyBonusService;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Расчёт квартальной премии отдела.
 *
 * Показывает не только итог, но и сколько партнёров не добрали до порога:
 * на живых данных ступень не достигается, и без этого числа экран и отчёт
 * выглядят поражением, а не задачей.
 */
class CalculateQuarterlyBonus extends Command
{
    protected $signature = 'motivation:calculate-quarter {quarter? : Первый месяц квартала Y-m; по умолчанию текущий}';

    protected $description = 'Посчитать квартальную премию отдела: поклиентный зачёт, ступень, сумма';

    public function handle(QuarterlyBonusService $service): int
    {
        $quarter = $this->argument('quarter')
            ? CarbonImmutable::parse((string) $this->argument('quarter').'-01')->startOfQuarter()
            : CarbonImmutable::now()->startOfQuarter();

        $bonus = $service->recalculate($quarter);

        if ($bonus->isFrozen()) {
            $this->warn(sprintf('Премия за квартал уже %s — пересчёт не выполняется.',
                $bonus->status === MotivationQuarterlyBonus::STATUS_PAID ? 'выплачена' : 'утверждена'));
        }

        $snapshot = (array) $bonus->snapshot;

        $this->info(sprintf('Квартал с %s', $quarter->format('m.Y')));
        $this->line(sprintf('Кандидатов (новых партнёров): %d', (int) ($snapshot['candidates'] ?? 0)));
        $this->line(sprintf('Квалифицировано при пороге %s: %d',
            Money::rub($snapshot['threshold'] ?? 0), $bonus->qualified_count));
        $this->line(sprintf('Ступень: %s, премия: %s',
            $bonus->step_reached > 0 ? (string) $bonus->step_reached : 'не достигнута',
            Money::rub((float) $bonus->amount)));

        $next = $snapshot['next_step'] ?? null;

        if (is_array($next)) {
            $this->line(sprintf('До следующей ступени: %d партнёров, она даёт %s',
                (int) $next['count'], Money::rub((float) $next['amount'])));
        }

        $short = MotivationQuarterlyQualification::query()
            ->forQuarter($quarter)
            ->where('qualified', false)
            ->orderByDesc('shipments_amount')
            ->limit(10)
            ->get();

        if ($short->isNotEmpty()) {
            $this->newLine();
            $this->line('Ближе всех к порогу:');
            $this->table(['Партнёр', 'Отгружено', 'Не хватает'], $short->map(
                fn (MotivationQuarterlyQualification $row): array => [
                    (string) ($row->partner->display_name ?? ('#'.$row->user_id)),
                    Money::rub((float) $row->shipments_amount - (float) $row->returns_amount),
                    Money::rub(max(0, (float) ($snapshot['threshold'] ?? 0) - (float) $row->shipments_amount + (float) $row->returns_amount)),
                ],
            )->all());
        }

        return self::SUCCESS;
    }
}

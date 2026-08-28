<?php

namespace App\Console\Commands\Debt;

use App\Enums\DebtLevel;
use App\Services\Debt\DebtStateService;
use App\Support\Debt\DebtControl;
use Illuminate\Console\Command;

/**
 * Ночной пересчёт лестницы долга (карточка debt-02).
 *
 * Идёт после mail:finance-scan. В тени пишет «я бы сделала» — сводку показать
 * заказчику до включения действий.
 */
class RecalculateDebtStates extends Command
{
    protected $signature = 'debt:recalculate
        {--user=* : Пересчитать только этих партнёров (id)}
        {--dry-run : Посчитать в тени независимо от режима}';

    protected $description = 'Пересчитать ступени лестницы долга по регистру взаиморасчётов';

    public function handle(DebtStateService $service): int
    {
        if (! DebtControl::enabled()) {
            $this->info('Лестница долга выключена (DEBT_CONTROL_ENABLED=false) — пересчёт не выполнялся.');

            return self::SUCCESS;
        }

        $userIds = array_values(array_filter(array_map('intval', (array) $this->option('user'))));
        $dryRun = $this->option('dry-run') ? true : null;

        $report = $service->recalculate(
            dryRun: $dryRun,
            onlyUserIds: $userIds === [] ? null : $userIds,
        );

        $this->line(sprintf(
            'Режим: %s. Партнёров: %d, пар: %d, переходов: %d, истёкших разблокировок: %d.',
            $report['dry_run'] ? 'тень (dry_run)' : 'бой',
            $report['users'],
            $report['pairs'],
            count($report['transitions']),
            $report['expired_pauses'],
        ));

        $this->table(
            ['Ступень', 'Переходов'],
            array_map(
                fn (DebtLevel $level): array => [$level->label(), $report['levels'][$level->value] ?? 0],
                DebtLevel::cases(),
            ),
        );

        if ($report['transitions'] !== [] && $this->getOutput()->isVerbose()) {
            $this->table(
                ['Партнёр', 'Контрагент', 'Было', 'Стало', 'Почему'],
                array_map(static fn (array $row): array => [
                    $row['user_id'], $row['company_id'], $row['from'], $row['to'], $row['reason'],
                ], $report['transitions']),
            );
        }

        return self::SUCCESS;
    }
}

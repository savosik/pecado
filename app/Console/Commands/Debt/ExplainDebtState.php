<?php

namespace App\Console\Commands\Debt;

use App\Models\User;
use App\Services\Debt\DebtStateService;
use Illuminate\Console\Command;

/**
 * «Почему у клиента такая ступень» — одной строкой и с детализацией.
 */
class ExplainDebtState extends Command
{
    protected $signature = 'debt:explain {user : id партнёра}';

    protected $description = 'Объяснить ступень лестницы долга по партнёру';

    public function handle(DebtStateService $service): int
    {
        $user = User::query()->find((int) $this->argument('user'));

        if ($user === null) {
            $this->error('Партнёр не найден.');

            return self::FAILURE;
        }

        $explain = $service->explain($user);
        $partner = $explain['partner'];

        $this->line(sprintf('%s (id %d)', $user->display_name, $user->getKey()));

        if ($partner === null) {
            $this->info('Ступень не считалась: просрочки по регистру нет.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s%s — %s',
            $partner['level_label'],
            $partner['dry_run'] ? ' (тень)' : '',
            $partner['reason'],
        ));

        $this->table(
            ['Контрагент', 'Ступень', 'Просрочка, ₽', 'Возраст, дн.', 'Почему'],
            array_map(static fn (array $row): array => [
                $row['company_name'] ?? ('#'.$row['company_id']),
                $row['level_label'],
                number_format($row['overdue_amount'], 2, ',', ' '),
                $row['age_days'],
                $row['reason'],
            ], $explain['contractors']),
        );

        if ($explain['active_pause'] !== null) {
            $pause = $explain['active_pause'];
            $this->info(sprintf('Разблокировка до %s (%s): %s', $pause['until'], $pause['author'] ?? '—', $pause['reason']));
        }

        return self::SUCCESS;
    }
}

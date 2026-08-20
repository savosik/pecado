<?php

namespace App\Console\Commands\Notifications;

use App\Services\Notifications\Pulse\FinanceScanner;
use Illuminate\Console\Command;

/**
 * Ежедневный обход финансового состояния для пульта уведомлений.
 *
 * Просрочка не «случается» — она длится, поэтому сигналы порождаются
 * на переходах: возникла, выросла, погашена. Прогон в один и тот же день
 * повторного письма не даёт.
 */
class FinanceScan extends Command
{
    protected $signature = 'notifications:finance-scan
        {--horizon=3 : За сколько дней предупреждать о сроке оплаты}
        {--dry-run : Посчитать, но не отправлять}';

    protected $description = 'Найти финансовые поводы для уведомлений: срок оплаты, просрочка, погашение';

    public function handle(FinanceScanner $scanner): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $scanner->scan((int) $this->option('horizon'), $dryRun);

        $this->info($dryRun ? 'Пробный обход (без отправки):' : 'Обход завершён:');
        $this->line("  подходит срок оплаты: {$result['due_soon']}");
        $this->line("  возникла просрочка:   {$result['started']}");
        $this->line("  просрочка выросла:    {$result['grew']}");
        $this->line("  просрочка погашена:   {$result['cleared']}");

        return self::SUCCESS;
    }
}

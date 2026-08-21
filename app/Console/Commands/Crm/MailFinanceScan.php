<?php

namespace App\Console\Commands\Crm;

use App\Services\Crm\Mail\Sources\FinanceScanner;
use Illuminate\Console\Command;

/**
 * Ежедневный обход финансового состояния для потока писем.
 *
 * Просрочка не «случается» — она длится, поэтому письма появляются
 * на переходах: возникла, выросла, погашена. Прогон в один и тот же день
 * повторного письма не даёт.
 */
class MailFinanceScan extends Command
{
    protected $signature = 'mail:finance-scan
        {--horizon=3 : За сколько дней предупреждать о сроке оплаты}
        {--dry-run : Посчитать, но писем не создавать}';

    protected $description = 'Собрать письма по финансовым поводам: срок оплаты, просрочка, погашение';

    public function handle(FinanceScanner $scanner): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $scanner->scan((int) $this->option('horizon'), $dryRun);

        $this->info($dryRun ? 'Пробный обход (писем не создано):' : 'Обход завершён:');
        $this->line("  подходит срок оплаты: {$result['due_soon']}");
        $this->line("  возникла просрочка:   {$result['started']}");
        $this->line("  просрочка выросла:    {$result['grew']}");
        $this->line("  просрочка погашена:   {$result['cleared']}");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands\Crm;

use App\Services\Crm\Mail\Sources\WeeklyReconciliation;
use Illuminate\Console\Command;

/**
 * Понедельничные поводы вокруг сверки.
 *
 * Расписание живёт здесь, а не в настройке уведомления: клиент подписывается
 * на событие «сводка за неделю», а когда оно случается — вопрос кода.
 */
class MailWeeklyReconciliation extends Command
{
    protected $signature = 'mail:weekly-reconciliation {--dry-run : Только показать, скольким ушло бы}';

    protected $description = 'Собрать понедельничные поводы: сводка актов сверки и акты для должников';

    public function handle(WeeklyReconciliation $source): int
    {
        $result = $source->run((bool) $this->option('dry-run'));

        $prefix = $this->option('dry-run') ? 'Ушло бы' : 'Собрано';

        $this->info("{$prefix} сводок актов за неделю: {$result['summaries']}");
        $this->info("{$prefix} актов сверки должникам: {$result['debtors']}");

        return self::SUCCESS;
    }
}

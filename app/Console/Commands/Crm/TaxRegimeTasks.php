<?php

namespace App\Console\Commands\Crm;

use App\Services\Crm\TaxRegime\TaxRegimeTaskPlanner;
use Illuminate\Console\Command;

/**
 * Задачи «Налоговый режим: уточнить у партнёра» по покупающим юрлицам без
 * актуального ответа, и автозакрытие тех, по которым ответы уже собраны.
 *
 * Идемпотентна: пока у партнёра открыта такая задача, вторая не ставится.
 */
class TaxRegimeTasks extends Command
{
    protected $signature = 'crm:tax-regime-tasks';

    protected $description = 'Поставить менеджерам задачи уточнить налоговый режим покупающих юрлиц и закрыть выполненные';

    public function handle(TaxRegimeTaskPlanner $planner): int
    {
        if (! config('crm_tax_regime.tasks.enabled')) {
            $this->info('Задачи по налоговому режиму выключены (CRM_TAX_REGIME_TASKS_ENABLED).');

            return self::SUCCESS;
        }

        $result = $planner->run();

        $this->info("Задач заведено: {$result['created']}, закрыто автоматически: {$result['closed']}");

        return self::SUCCESS;
    }
}

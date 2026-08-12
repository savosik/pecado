<?php

namespace App\Console\Commands;

use App\Services\Crm\BackInStockDraftService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Черновики писем «товар снова в наличии» (crm-31).
 *
 * Ничего не отправляет: результат прогона — черновики в почте менеджеров,
 * которые они правят и отправляют руками.
 */
class CrmBackInStockDrafts extends Command
{
    protected $signature = 'crm:back-in-stock-drafts {--since= : С какой даты считать возвраты товара}';

    protected $description = 'Создать черновики писем клиентам о вернувшихся в продажу товарах';

    public function handle(BackInStockDraftService $service): int
    {
        $since = $this->option('since') !== null
            ? Carbon::parse((string) $this->option('since'))
            : null;

        $result = $service->run($since);

        if ($result['drafts'] === 0) {
            $this->info('Новых поводов написать клиентам нет.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Создано черновиков: %d (клиентов %d, товаров %d).',
            $result['drafts'],
            $result['clients'],
            $result['products'],
        ));

        if ($result['skipped'] > 0) {
            $this->warn("Пропущено клиентов без email или без менеджера: {$result['skipped']}.");
        }

        if ($result['truncated']) {
            $this->warn('Список обрезан лимитом — часть клиентов не попала в прогон, подробности в логе.');
        }

        return self::SUCCESS;
    }
}

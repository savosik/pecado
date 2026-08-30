<?php

namespace App\Console\Commands\Payroll;

use App\Events\Payroll\PayrollInputsChanged;
use App\Services\Payroll\Invoices\PayrollInvoiceSettlementProjector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Ночной ребилд моста «накладная → срок → дата закрытия → задержка».
 *
 * Событийная проекция покрывает партнёров, по которым пришли движения; ребилд
 * догоняет остальное: смену менеджера у партнёра, поздно доехавшие графики,
 * изменения производственного календаря.
 */
class RebuildInvoiceSettlements extends Command
{
    protected $signature = 'payroll:rebuild-invoices
        {--since= : С какой даты документа (Y-m-d); по умолчанию — окно из config payroll.invoices.rebuild_months}
        {--silent-events : Не бросать событие пересчёта черновиков}';

    protected $description = 'Пересобрать сопоставление накладных с платежами для расчёта зарплаты';

    public function handle(PayrollInvoiceSettlementProjector $projector): int
    {
        $since = $this->option('since')
            ? CarbonImmutable::parse((string) $this->option('since'))
            : CarbonImmutable::now()->subMonths((int) config('payroll.invoices.rebuild_months', 6))->startOfMonth();

        $stats = $projector->rebuild($since);

        $this->info(sprintf(
            'Реализаций с %s: %d; дата закрытия восстановлена у %d (%s); на ручную разметку: %d.',
            $since->toDateString(),
            $stats['shipments'],
            $stats['matched'],
            $stats['shipments'] > 0 ? round($stats['matched'] / $stats['shipments'] * 100).' %' : '—',
            $stats['needs_review'],
        ));

        if ($stats['managers'] !== [] && ! $this->option('silent-events')) {
            PayrollInputsChanged::dispatch($stats['managers'], 'invoices:rebuild');
        }

        return self::SUCCESS;
    }
}

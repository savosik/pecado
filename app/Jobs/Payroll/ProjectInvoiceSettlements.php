<?php

namespace App\Jobs\Payroll;

use App\Events\Payroll\PayrollInputsChanged;
use App\Services\Payroll\Invoices\PayrollInvoiceSettlementProjector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Пересобрать мост «накладная → дата закрытия» по партнёрам после события регистра.
 *
 * В очереди, а не в воркере 1С: сообщение уже обработано, а проекция читает
 * все реализации партнёра за окно ребилда. Идемпотентно — строки перезаписываются.
 */
class ProjectInvoiceSettlements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 60, 120];

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public readonly array $userIds, public readonly string $source = 'settlements')
    {
        $this->queue = 'default';
    }

    public function handle(PayrollInvoiceSettlementProjector $projector): void
    {
        $stats = $projector->projectPartners($this->userIds);

        if ($stats['managers'] !== []) {
            PayrollInputsChanged::dispatch($stats['managers'], 'invoices:'.$this->source);
        }
    }
}

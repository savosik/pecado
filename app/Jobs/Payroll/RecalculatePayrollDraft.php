<?php

namespace App\Jobs\Payroll;

use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Пересчёт черновика зарплаты менеджера за месяц — с дебаунсом.
 *
 * Ставится с задержкой `payroll.recalculate.debounce_seconds`; пока джоб ждёт
 * в очереди, повторные события по той же паре менеджер × месяц отбрасываются
 * (уникальность до начала обработки). Событие, пришедшее во время самого
 * расчёта, ставит следующий джоб — замок уже снят. Так пачка отгрузок из 1С
 * даёт один пересчёт, а не десять.
 */
class RecalculatePayrollDraft implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 900;

    /**
     * @param  string  $month  первое число месяца, Y-m-d
     */
    public function __construct(
        public readonly int $managerId,
        public readonly string $month,
        public readonly string $source = 'event',
    ) {
        $this->queue = 'default';
    }

    public function uniqueId(): string
    {
        return 'payroll-draft:'.$this->managerId.':'.$this->month;
    }

    public function handle(PayrollCalculationService $service): void
    {
        $service->recalculateDraft($this->managerId, CarbonImmutable::parse($this->month), $this->source);
    }
}

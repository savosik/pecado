<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Services\Settlements\SettlementProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Репроекция колонок оплаты из плановых строк регистра (fin-11, волна 3).
 *
 * Штатно проекция обновляется на каждом `payment_schedule.updated`; команда —
 * страховочная сеть и разовый бэкфил при переходе: прежний писатель
 * (`PaymentAllocationService`) снесён, и накопленные им значения нужно один раз
 * пересчитать из регистра. Наследница `payments:recalculate` по роли.
 */
class ProjectSettlementDocuments extends Command
{
    protected $signature = 'settlements:project-documents
        {--kind=all : Что проецировать: shipment, order или all}
        {--uuid=* : Только эти document_uuid (точечная репроекция)}
        {--chunk=500 : Размер пачки документов}
        {--dry-run : Посчитать изменения, ничего не записывая}';

    protected $description = 'Пересчитать shipments.paid_amount/payment_status/payment_due_date и orders.prepaid_amount из плановых строк регистра';

    public function handle(SettlementProjector $projector): int
    {
        $kind = (string) $this->option('kind');

        if (! in_array($kind, ['all', 'shipment', 'order'], true)) {
            $this->error("Недопустимый --kind: '{$kind}'. Ожидается shipment, order или all.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $totals = ['processed' => 0, 'changed' => 0, 'missing' => 0];

        foreach (['shipment' => Shipment::class, 'order' => Order::class] as $documentKind => $model) {
            if ($kind !== 'all' && $kind !== $documentKind) {
                continue;
            }

            $this->projectKind($projector, $documentKind, $model, $chunk, $dryRun, $totals);
        }

        if ($kind !== 'order') {
            $this->reportShipmentsWithoutPlan();
        }

        $this->info(sprintf(
            '%s: документов %d, изменено %d, не найдено на сайте %d.',
            $dryRun ? 'Прогон без записи' : 'Готово',
            $totals['processed'],
            $totals['changed'],
            $totals['missing'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Shipment|Order>  $model
     * @param  array{processed: int, changed: int, missing: int}  $totals
     */
    private function projectKind(
        SettlementProjector $projector,
        string $documentKind,
        string $model,
        int $chunk,
        bool $dryRun,
        array &$totals,
    ): void {
        $uuids = SettlementEntry::query()
            ->plans()
            ->where('document_kind', $documentKind)
            ->when($this->option('uuid') !== [], fn ($query) => $query->whereIn('document_uuid', $this->option('uuid')))
            ->whereNotNull('document_uuid')
            ->distinct()
            ->pluck('document_uuid');

        $this->line(sprintf('%s: документов с планом — %d', $documentKind, $uuids->count()));

        foreach ($uuids->chunk($chunk) as $batch) {
            /** @var Collection<int, Shipment|Order> $documents */
            $documents = $model::query()->withoutGlobalScopes()->withTrashed()
                ->whereIn('uuid', $batch)
                ->get();

            $totals['missing'] += $batch->count() - $documents->count();

            foreach ($documents as $document) {
                $totals['processed']++;

                if ($dryRun) {
                    // Без записи: dry-run отвечает на вопрос «сколько документов
                    // будет затронуто», а не «какими станут значения».
                    continue;
                }

                $before = $this->snapshot($document);

                $document instanceof Shipment
                    ? $projector->projectShipment($document)
                    : $projector->projectOrder($document);

                if ($this->snapshot($document->refresh()) !== $before) {
                    $totals['changed']++;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Shipment|Order $document): array
    {
        return $document instanceof Shipment
            ? [
                'paid_amount' => (string) $document->paid_amount,
                'payment_status' => $document->payment_status,
                'payment_due_date' => $document->payment_due_date?->toDateString(),
            ]
            : ['prepaid_amount' => (string) $document->prepaid_amount];
    }

    /**
     * Реализации с ненулевыми колонками оплаты, но без плановых строк регистра:
     * их значения остались от снесённого писателя, регистр о них молчит.
     * Список нужен для ручного разбора — вопрос о таких документах задан 1С.
     */
    private function reportShipmentsWithoutPlan(): void
    {
        $orphans = Shipment::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query
                ->where('paid_amount', '>', 0)
                ->orWhere('payment_status', '!=', Shipment::PAYMENT_UNPAID))
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('settlement_entries as e')
                ->where('e.nature', SettlementEntry::NATURE_PLAN)
                ->whereColumn('e.document_uuid', 'shipments.uuid'))
            ->count();

        if ($orphans > 0) {
            $this->warn("Реализаций с оплатой, но без плана в регистре: {$orphans} — их колонки не тронуты (значения прежнего писателя).");
        }
    }
}

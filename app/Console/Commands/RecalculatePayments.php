<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Shipment;
use App\Services\Payments\PaymentAllocationService;
use Illuminate\Console\Command;

/**
 * Полный пересчёт денежных агрегатов платежей и оплаты реализаций.
 *
 * ЗАЧЕМ. `payments.allocated_amount / unallocated_amount` и `shipments.paid_amount /
 * payment_status / paid_at` — денормализация: их держит в актуальном состоянии
 * PaymentAllocationService при каждом сообщении из 1С. Команда — страховочная сеть
 * для случаев, когда обычный путь не сработал:
 *
 *   - после первичной выгрузки платежей (часть строк приехала раньше реализаций
 *     и доклеилась постфактум);
 *   - после ручного вмешательства в БД;
 *   - после инцидента с воркером, когда часть сообщений ушла в DLQ.
 *
 * Пересчёт идемпотентен: он полная функция от состояния БД, а не инкремент,
 * поэтому запускать его безопасно в любой момент и сколько угодно раз.
 *
 * Побочный эффект — доклейка осиротевших строк разнесения: если реализация
 * приехала, а строка осталась с shipment_id = NULL (например, сообщение
 * реализации обработалось до деплоя этой фичи), команда её свяжет.
 */
class RecalculatePayments extends Command
{
    protected $signature = 'payments:recalculate
        {--chunk=500 : Размер чанка при обходе таблиц}';

    protected $description = 'Пересчитать разнесение платежей и оплату реализаций';

    public function handle(PaymentAllocationService $service): int
    {
        $chunk = max(50, (int) $this->option('chunk'));

        $linked = $this->linkOrphans($chunk);
        $this->info("Доклеено осиротевших строк разнесения: {$linked}");

        $payments = 0;
        Payment::withTrashed()->chunkById($chunk, function ($batch) use ($service, &$payments) {
            foreach ($batch as $payment) {
                $service->recalculatePayment($payment);
                $payments++;
            }
        });
        $this->info("Пересчитано платежей: {$payments}");

        $shipments = 0;
        Shipment::withTrashed()->select('id')->chunkById($chunk, function ($batch) use ($service, &$shipments) {
            $service->recalculateShipments($batch->pluck('id')->all());
            $shipments += $batch->count();
        });
        $this->info("Пересчитано реализаций: {$shipments}");

        return self::SUCCESS;
    }

    /**
     * Связать строки разнесения с реализациями, приехавшими после платежа.
     *
     * Идём от строк, а не от реализаций: осиротевших строк на порядки меньше,
     * чем всех реализаций.
     */
    private function linkOrphans(int $chunk): int
    {
        $linked = 0;

        PaymentAllocation::query()
            ->whereNull('shipment_id')
            ->chunkById($chunk, function ($batch) use (&$linked) {
                $map = Shipment::withoutGlobalScopes()->withTrashed()
                    ->whereIn('uuid', $batch->pluck('shipment_uuid')->unique())
                    ->pluck('id', 'uuid');

                foreach ($batch as $allocation) {
                    $shipmentId = $map->get($allocation->shipment_uuid);

                    if ($shipmentId) {
                        $allocation->forceFill(['shipment_id' => $shipmentId])->saveQuietly();
                        $linked++;
                    }
                }
            });

        return $linked;
    }
}

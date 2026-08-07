<?php

namespace App\Services\Erp\Support;

use App\Models\Shipment;
use App\Services\Payments\PaymentScheduleService;
use Illuminate\Support\Facades\Log;

/**
 * Приём графика оплаты («Правила оплаты» 1С) из payload-а реализации (v15.12.0).
 *
 * Ключевое правило контракта — **отсутствие ключа `payment_schedule` и пустой массив
 * означают разное**: ключа нет — сохранённый график не трогаем (1С меняет только
 * позиции документа), `[]` — очищаем полностью. Ровно та же ловушка, что
 * с `allocations` в платежах v15.11.0, поэтому проверка идёт через array_key_exists,
 * а не isset.
 *
 * Когда ключа нет, пересчёт всё равно запускается: оплата реализации могла измениться
 * между сообщениями, а строки графика об этом ещё не знают.
 */
trait SyncsShipmentPaymentSchedule
{
    protected function syncPaymentSchedule(Shipment $shipment, array $payload, string $context): void
    {
        $service = app(PaymentScheduleService::class);

        if (! array_key_exists('payment_schedule', $payload)) {
            // Ключа нет — график не трогаем, но раскладку освежаем: внутри
            // redistributeMany есть отсечка реализаций вообще без графика.
            $service->redistributeMany([$shipment->id]);

            return;
        }

        $rows = $payload['payment_schedule'];

        if (! is_array($rows)) {
            Log::warning($context.': payment_schedule не массив, график не изменён', [
                'uuid' => $shipment->uuid,
                'type' => get_debug_type($rows),
            ]);

            return;
        }

        $service->sync($shipment, $rows);

        Log::info($context.': график оплаты обновлён', [
            'uuid' => $shipment->uuid,
            'lines_received' => count($rows),
            'lines_saved' => $shipment->paymentSchedules()->count(),
            'payment_due_date' => $shipment->payment_due_date?->toDateString(),
        ]);
    }
}

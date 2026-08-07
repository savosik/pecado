<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Единственный писатель денежных агрегатов: `payments.allocated_amount`,
 * `payments.unallocated_amount`, `shipments.paid_amount / payment_status / paid_at`.
 *
 * Все пересчёты здесь — **полная функция от состояния БД** (`SELECT SUM ... GROUP BY`),
 * никогда инкремент. Это и есть условие, при котором денормализация безопасна:
 * повторная доставка того же сообщения из RabbitMQ даёт тот же результат,
 * а не удваивает суммы.
 */
class PaymentAllocationService
{
    /**
     * Заменить разнесение платежа целиком.
     *
     * Delete-and-recreate, как `HandleShipmentCreated::syncItems()`. Уникального ключа
     * на (payment_id, shipment_uuid) нет намеренно: 1С вправе прислать две строки на одну
     * реализацию, различающиеся договором или статьёй ДДС, которых сайт не знает.
     *
     * @param  array<int, mixed>  $rows  Содержимое `allocations` из payload — приходит
     *                                   из 1С и типизировано только схемой, поэтому
     *                                   каждая строка проверяется отдельно
     */
    public function sync(Payment $payment, array $rows): void
    {
        // Реализации, затронутые ДО замены. Без этого снятое разнесение оставило бы
        // на реализации оплату-призрака: строки уже удалены, и пересчитывать нечего.
        $affected = $payment->allocations()->whereNotNull('shipment_id')->pluck('shipment_id')->all();

        DB::transaction(function () use ($payment, $rows, &$affected) {
            $payment->allocations()->delete();

            foreach (array_values($rows) as $index => $row) {
                $shipmentUuid = is_array($row) ? ($row['shipment_uuid'] ?? null) : null;

                if (! $shipmentUuid) {
                    Log::warning('PaymentAllocationService: строка расшифровки без shipment_uuid пропущена', [
                        'payment_uuid' => $payment->uuid,
                        'line' => $index + 1,
                    ]);

                    continue;
                }

                // target_type заведён на вырост: сегодня расшифровка бывает только
                // по реализациям, но контракт допускает другие документы.
                $targetType = $row['target_type'] ?? 'shipment';

                if (! in_array($targetType, [null, 'shipment'], true)) {
                    Log::warning('PaymentAllocationService: строка расшифровки не по реализации пропущена', [
                        'payment_uuid' => $payment->uuid,
                        'target_type' => $targetType,
                        'line' => $index + 1,
                    ]);

                    continue;
                }

                $shipmentId = Shipment::withoutGlobalScopes()->where('uuid', $shipmentUuid)->value('id');

                $payment->allocations()->create([
                    'shipment_uuid' => $shipmentUuid,
                    'shipment_id' => $shipmentId,
                    'order_uuid' => $row['order_uuid'] ?? null,
                    'amount' => round((float) ($row['amount'] ?? 0), 2),
                    'line_number' => $row['line_number'] ?? $index + 1,
                ]);

                if ($shipmentId) {
                    $affected[] = $shipmentId;
                }
            }

            $this->recalculatePayment($payment);
            $this->recalculateShipments(array_unique($affected));
        });
    }

    /**
     * Пересчитать разнесённую и нераспределённую суммы платежа.
     */
    public function recalculatePayment(Payment $payment): void
    {
        $allocated = round((float) $payment->allocations()->sum('amount'), 2);

        $payment->forceFill([
            'allocated_amount' => $allocated,
            'unallocated_amount' => round((float) $payment->amount - $allocated, 2),
        ])->saveQuietly();
    }

    /**
     * Пересчитать оплату реализаций.
     *
     * @param  array<int, int>  $shipmentIds
     */
    public function recalculateShipments(array $shipmentIds): void
    {
        $shipmentIds = array_values(array_unique(array_filter($shipmentIds)));

        if ($shipmentIds === []) {
            return;
        }

        foreach (array_chunk($shipmentIds, 500) as $chunk) {
            $shipments = Shipment::withoutGlobalScopes()->withTrashed()
                ->whereIn('id', $chunk)
                ->get(['id', 'total_amount', 'currency_code', 'paid_amount', 'payment_status', 'paid_at']);

            if ($shipments->isEmpty()) {
                continue;
            }

            $rows = $this->aggregateFor($chunk);

            foreach ($shipments as $shipment) {
                $this->applyAggregate($shipment, $rows->get($shipment->id));
            }

            // v15.12.0: изменился факт оплаты — переехал план. Строки графика
            // гасятся FIFO от новой суммы, поэтому пересчёт идёт строго после
            // записи агрегатов, а не параллельно с ней.
            app(PaymentScheduleService::class)->redistributeMany($chunk);
        }
    }

    /**
     * Суммы разнесений по реализациям из указанного чанка.
     *
     * Возвраты (`direction = 'out'`) вычитаются: они уменьшают оплату реализации.
     * Удалённые платежи не учитываются, но их строки остаются в БД — восстановление
     * платежа возвращает оплату без повторной доставки расшифровки.
     *
     * @param  array<int, int>  $shipmentIds
     * @return \Illuminate\Support\Collection<int|string, \stdClass>
     */
    private function aggregateFor(array $shipmentIds): \Illuminate\Support\Collection
    {
        return DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('shipments as s', 's.id', '=', 'pa.shipment_id')
            ->whereIn('pa.shipment_id', $shipmentIds)
            ->whereNull('p.deleted_at')
            // Оплата засчитывается только в валюте реализации: сложить рубли с тенге
            // по неизвестному курсу сайт не вправе. NULL с обеих сторон трактуем как
            // «валюта не указана» — это одна и та же валюта по умолчанию.
            ->where(function ($query) {
                $query->whereColumn('p.currency_code', 's.currency_code')
                    ->orWhere(function ($inner) {
                        $inner->whereNull('p.currency_code')->whereNull('s.currency_code');
                    });
            })
            ->groupBy('pa.shipment_id')
            ->selectRaw('pa.shipment_id as shipment_id')
            ->selectRaw("SUM(CASE WHEN p.direction = 'out' THEN -pa.amount ELSE pa.amount END) as paid")
            ->selectRaw('MAX(p.date) as last_payment_date')
            ->get()
            ->keyBy('shipment_id');
    }

    /**
     * Записать агрегат в реализацию.
     *
     * Реализация, попавшая в пересчёт, но выпавшая из результата запроса (разнесение
     * снято, платёж удалён, валюты разошлись), обнуляется явно — иначе на ней осталось бы
     * старое значение.
     */
    private function applyAggregate(Shipment $shipment, ?object $row): void
    {
        $paid = round((float) ($row->paid ?? 0), 2);
        $total = round((float) $shipment->total_amount, 2);
        $status = $this->statusFor($total, $paid);

        $paidAt = $shipment->paid_at;

        if (in_array($status, [Shipment::PAYMENT_PAID, Shipment::PAYMENT_OVERPAID], true)) {
            // Дата закрытия — момент последнего платежа, а не «сейчас»:
            // при повторном пересчёте она не должна уезжать вперёд.
            $paidAt = $row->last_payment_date ?? $paidAt;
        } else {
            $paidAt = null;
        }

        // withoutEvents + saveQuietly: пересчёт не должен дёргать Scout и обсерверы —
        // при первичной выгрузке это десятки тысяч лишних переиндексаций.
        Shipment::withoutEvents(function () use ($shipment, $paid, $status, $paidAt) {
            $shipment->forceFill([
                'paid_amount' => $paid,
                'payment_status' => $status,
                'paid_at' => $paidAt,
            ])->saveQuietly();
        });
    }

    /**
     * Статус оплаты по сумме документа и оплаченному.
     *
     * Реализация с нулевой суммой считается оплаченной: платить по ней нечего,
     * и статус «не оплачена» вводил бы менеджера в заблуждение.
     */
    private function statusFor(float $total, float $paid): string
    {
        if ($paid > $total + Payment::EPSILON) {
            return Shipment::PAYMENT_OVERPAID;
        }

        if ($total <= Payment::EPSILON) {
            return Shipment::PAYMENT_PAID;
        }

        if ($paid >= $total - Payment::EPSILON) {
            return Shipment::PAYMENT_PAID;
        }

        return $paid > Payment::EPSILON ? Shipment::PAYMENT_PARTIAL : Shipment::PAYMENT_UNPAID;
    }

    /**
     * Доклеить строки разнесения, приехавшие раньше реализации, и пересчитать оплату.
     *
     * Вызывается из обработчиков реализаций: платежи и реализации идут разными
     * очередями без гарантии порядка, а первичная выгрузка гарантированно даёт
     * часть платежей раньше их реализаций.
     *
     * @return int Сколько строк удалось связать
     */
    public function linkOrphanAllocations(Shipment $shipment): int
    {
        $linked = PaymentAllocation::query()
            ->where('shipment_uuid', $shipment->uuid)
            ->whereNull('shipment_id')
            ->update(['shipment_id' => $shipment->id]);

        if ($linked > 0) {
            $this->recalculateShipments([$shipment->id]);
        }

        return $linked;
    }
}

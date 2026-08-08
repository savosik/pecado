<?php

namespace App\Services\Payments;

use App\Models\Order;
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

        // То же для заказов: снятая предоплата обязана исчезнуть из карточки заказа.
        $affectedOrders = $payment->allocations()->whereNotNull('order_uuid')->pluck('order_uuid')->all();

        DB::transaction(function () use ($payment, $rows, &$affected, &$affectedOrders) {
            $payment->allocations()->delete();

            foreach (array_values($rows) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $line = $this->parseRow($payment, $row, $index + 1);

                if ($line === null) {
                    continue;
                }

                $payment->allocations()->create($line);

                if ($line['shipment_id'] !== null) {
                    $affected[] = $line['shipment_id'];
                }

                if ($line['order_uuid'] !== null) {
                    $affectedOrders[] = $line['order_uuid'];
                }
            }

            $this->recalculatePayment($payment);
            $this->recalculateShipments(array_unique($affected));
            $this->recalculateOrders(array_unique($affectedOrders));
        });
    }

    /**
     * Разбор строки расшифровки. null — строку принять нельзя.
     *
     * v15.16.0: расшифровка не ограничена реализациями. Ключ связи зависит
     * от `target_type`, и требование к строке — тоже:
     *
     *   shipment (или тип не передан) → обязателен shipment_uuid
     *   order                          → обязателен order_uuid
     *   other                          → достаточно суммы
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function parseRow(Payment $payment, array $row, int $line): ?array
    {
        // Отсутствие ключа и явный null означают реализацию — совместимость
        // с v15.11.0, когда другого типа контракт не допускал.
        $targetType = $row['target_type'] ?? PaymentAllocation::TARGET_SHIPMENT;

        if (! in_array($targetType, PaymentAllocation::TARGET_TYPES, true)) {
            Log::warning('PaymentAllocationService: строка расшифровки с неизвестным типом документа пропущена', [
                'payment_uuid' => $payment->uuid,
                'target_type' => $targetType,
                'line' => $line,
            ]);

            return null;
        }

        $shipmentUuid = $row['shipment_uuid'] ?? null;
        $orderUuid = $row['order_uuid'] ?? null;

        if ($targetType === PaymentAllocation::TARGET_SHIPMENT && ! $shipmentUuid) {
            Log::warning('PaymentAllocationService: строка расшифровки по реализации без shipment_uuid пропущена', [
                'payment_uuid' => $payment->uuid,
                'line' => $line,
            ]);

            return null;
        }

        if ($targetType === PaymentAllocation::TARGET_ORDER && ! $orderUuid) {
            Log::warning('PaymentAllocationService: строка расшифровки по заказу без order_uuid пропущена', [
                'payment_uuid' => $payment->uuid,
                'line' => $line,
            ]);

            return null;
        }

        // shipment_id резолвим только для строк по реализации: у строки по заказу
        // shipment_uuid может быть заполнен справочно, но оплату она не закрывает.
        // Наличие uuid здесь уже гарантировано проверкой выше.
        $shipmentId = $targetType === PaymentAllocation::TARGET_SHIPMENT
            ? Shipment::withoutGlobalScopes()->where('uuid', $shipmentUuid)->value('id')
            : null;

        return [
            'target_type' => $targetType,
            'shipment_uuid' => $shipmentUuid,
            'shipment_id' => $shipmentId,
            'order_uuid' => $orderUuid,
            'target_uuid' => $row['target_uuid'] ?? null,
            'target_name' => $row['target_name'] ?? null,
            'amount' => round((float) ($row['amount'] ?? 0), 2),
            'line_number' => $row['line_number'] ?? $line,
        ];
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
     * Пересчитать предоплату заказов (v15.16.0).
     *
     * Предоплата — сумма строк расшифровки с `target_type = order`. Накладную она
     * не гасит: пока реализации нет, гасить нечего. Когда реализация появится,
     * 1С переразнесёт платёж и пришлёт его целиком заново — строка станет
     * `shipment` и попадёт уже в оплату реализации.
     *
     * Связь мягкая, по `order_uuid`: заказ мог не приехать на сайт вовсе.
     *
     * @param  array<int, string>  $orderUuids
     */
    public function recalculateOrders(array $orderUuids): void
    {
        $orderUuids = array_values(array_unique(array_filter($orderUuids)));

        if ($orderUuids === []) {
            return;
        }

        foreach (array_chunk($orderUuids, 500) as $chunk) {
            $rows = DB::table('payment_allocations as pa')
                ->join('payments as p', 'p.id', '=', 'pa.payment_id')
                ->whereIn('pa.order_uuid', $chunk)
                ->where('pa.target_type', PaymentAllocation::TARGET_ORDER)
                ->whereNull('p.deleted_at')
                ->groupBy('pa.order_uuid')
                ->selectRaw('pa.order_uuid as order_uuid')
                // Возврат уменьшает предоплату — та же логика, что у реализаций
                ->selectRaw("SUM(CASE WHEN p.direction = 'out' THEN -pa.amount ELSE pa.amount END) as prepaid")
                ->get()
                ->keyBy('order_uuid');

            $orders = Order::withoutGlobalScopes()->withTrashed()
                ->whereIn('uuid', $chunk)
                ->get(['id', 'uuid', 'prepaid_amount']);

            foreach ($orders as $order) {
                $prepaid = round((float) ($rows->get($order->uuid)->prepaid ?? 0), 2);

                if ((float) $order->prepaid_amount === $prepaid) {
                    continue;
                }

                // withoutEvents + saveQuietly: заказ не должен уехать обратно в 1С
                // из-за служебного пересчёта — на его сохранении висит публикация
                Order::withoutEvents(function () use ($order, $prepaid) {
                    $order->forceFill(['prepaid_amount' => $prepaid])->saveQuietly();
                });
            }
        }
    }

    /**
     * Доклеить предоплату к заказу, приехавшему позже платежа.
     *
     * Вызывается из обработчиков заказов: платежи и заказы идут разными очередями,
     * и предоплата регулярно приезжает раньше самого заказа.
     */
    public function recalculateOrder(Order $order): void
    {
        $this->recalculateOrders([$order->uuid]);
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
            ->where('target_type', PaymentAllocation::TARGET_SHIPMENT)
            ->whereNull('shipment_id')
            ->update(['shipment_id' => $shipment->id]);

        if ($linked > 0) {
            $this->recalculateShipments([$shipment->id]);
        }

        return $linked;
    }
}

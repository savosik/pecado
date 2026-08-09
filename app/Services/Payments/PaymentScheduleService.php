<?php

namespace App\Services\Payments;

use App\Models\PaymentAllocation;
use App\Models\Shipment;
use App\Models\ShipmentPaymentSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * График оплаты реализации: приём строк из 1С и погашение их фактическими платежами.
 *
 * Единственный писатель `shipment_payment_schedules.paid_amount` и
 * `shipments.payment_due_date` — тот же контракт, что у PaymentAllocationService:
 * **все пересчёты являются полной функцией от состояния БД, никогда инкрементом**.
 * Это и есть условие, при котором денормализация безопасна: повторная доставка
 * того же сообщения из RabbitMQ даёт тот же результат, а не удваивает суммы.
 *
 * Почему раскладку делает сайт, а не 1С: 1С разносит платёж по **реализациям**
 * (табличная часть «Расшифровка платежа»), а не по строкам графика. Связку
 * «какая строка закрыта» в обмене нет и не будет, поэтому сайт строит её сам —
 * FIFO по плановой дате.
 */
class PaymentScheduleService
{
    /**
     * Заменить график реализации целиком.
     *
     * Delete-and-recreate, как `HandleShipmentCreated::syncItems()` и
     * `PaymentAllocationService::sync()`. Вызывать только когда 1С прислала ключ
     * `payment_schedule`: отсутствие ключа означает «не трогать», и решение об этом
     * принимает обработчик, а не сервис.
     *
     * @param  array<int, mixed>  $rows  Содержимое `payment_schedule` из payload —
     *                                   приходит из 1С и типизировано только схемой,
     *                                   поэтому каждая строка проверяется отдельно
     */
    public function sync(Shipment $shipment, array $rows): void
    {
        DB::transaction(function () use ($shipment, $rows): void {
            $shipment->paymentSchedules()->delete();

            foreach (array_values($rows) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $dueDate = $this->parseDate($row['due_date'] ?? null);

                if ($dueDate === null) {
                    // Плановая дата — единственное, ради чего график забирается:
                    // без неё строка не попадёт ни в календарь, ни в расчёт
                    // ближайшего платежа. Роняем строку, но не весь документ.
                    Log::warning('PaymentScheduleService: строка графика без due_date пропущена', [
                        'shipment_uuid' => $shipment->uuid,
                        'line' => $index + 1,
                    ]);

                    continue;
                }

                $shipment->paymentSchedules()->create([
                    'line_number' => $this->intOrNull($row['line_number'] ?? null) ?? $index + 1,
                    'due_date' => $dueDate,
                    'amount' => round((float) ($row['amount'] ?? 0), 2),
                    'paid_amount' => 0,
                    'percent' => isset($row['percent']) ? round((float) $row['percent'], 4) : null,
                    'term_days' => $this->intOrNull($row['term_days'] ?? null),
                    'basis' => $this->enumOrNull($row['basis'] ?? null, ShipmentPaymentSchedule::BASES, 'basis', $shipment),
                    'basis_name' => $this->stringOrNull($row['basis_name'] ?? null),
                    'stage' => $this->enumOrNull($row['stage'] ?? null, ShipmentPaymentSchedule::STAGES, 'stage', $shipment),
                    'stage_name' => $this->stringOrNull($row['stage_name'] ?? null),
                    'order_uuid' => $this->stringOrNull($row['order_uuid'] ?? null),
                ]);
            }

            $this->redistribute($shipment);
        });
    }

    /**
     * Разложить оплату реализации по строкам графика (FIFO) и обновить
     * ближайшую плановую дату платежа.
     *
     * Порядок погашения — плановая дата, затем номер строки из 1С (скоуп
     * `inFifoOrder`). Именно в этом порядке строки показываются клиенту,
     * иначе он видел бы одну очерёдность, а закрывалась бы другая.
     */
    public function redistribute(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment): void {
            // Гонка реальна: реализации приходят через erp-documents-consumer,
            // платежи — через erp-payments-consumer. Каждый в один процесс,
            // но друг относительно друга они параллельны, и оба вызывают пересчёт
            // одной и той же реализации.
            $locked = Shipment::withoutGlobalScopes()->withTrashed()
                ->lockForUpdate()
                ->find($shipment->id, ['id', 'paid_amount', 'payment_due_date']);

            if ($locked === null) {
                return;
            }

            $remaining = round((float) $locked->paid_amount, 2);

            $lines = ShipmentPaymentSchedule::query()
                ->where('shipment_id', $locked->id)
                ->inFifoOrder()
                ->get();

            foreach ($lines as $line) {
                $amount = round((float) $line->amount, 2);
                // Переплата на последней строке не «переливается» дальше: строк
                // больше нет, а отрицательного остатка быть не должно.
                $paid = round(max(0.0, min($amount, $remaining)), 2);
                $remaining = round(max(0.0, $remaining - $paid), 2);

                if (round((float) $line->paid_amount, 2) !== $paid) {
                    $line->forceFill(['paid_amount' => $paid])->saveQuietly();
                }
            }

            // Авансы по заказам этой реализации — вторым проходом: прямое разнесение
            // имеет приоритет, и зачитывать аванс поверх него нельзя.
            $this->applyOrderPrepayments($lines->pluck('order_uuid')->all());

            $this->refreshDueDate($locked);

            // Переданный экземпляр держим в актуальном состоянии: обработчик
            // продолжает работать именно с ним, а запись ушла в $locked.
            $shipment->setAttribute('payment_due_date', $locked->payment_due_date);
            $shipment->syncOriginalAttribute('payment_due_date');
        });
    }

    /**
     * Зачесть авансы по заказам в строки графика.
     *
     * 1С разносит поступление либо на реализацию, либо на ЗАКАЗ. Второй случай —
     * почти половина денег, и без этого зачёта строка графика висела бы просроченной
     * навсегда: `shipments.paid_amount` от предоплаты по заказу не растёт и расти
     * не должен (это строгая проекция расшифровки платежа по реализациям).
     *
     * Считается в разрезе ЗАКАЗА, а не реализации: один заказ отгружают частями,
     * и зачесть его аванс каждой реализации целиком значило бы погасить долг дважды.
     * Аванс раскладывается по строкам всех реализаций заказа в порядке FIFO —
     * том же, в котором строки гасятся деньгами.
     *
     * Полная функция от состояния БД, как и остальные пересчёты сервиса:
     * повторный вызов даёт тот же результат.
     *
     * @param  array<int, string|null>  $orderUuids
     */
    public function applyOrderPrepayments(array $orderUuids): void
    {
        $orderUuids = array_values(array_unique(array_filter($orderUuids)));

        if ($orderUuids === []) {
            return;
        }

        // Пакетно, а не по заказу за раз: на проде 5592 заказа со графиком, и обход
        // поштучно дал бы 11 тыс. запросов на один пересчёт.
        foreach (array_chunk($orderUuids, 500) as $chunk) {
            $prepaidByOrder = $this->prepaidOf($chunk);

            $lines = ShipmentPaymentSchedule::query()
                ->whereIn('order_uuid', $chunk)
                ->inFifoOrder()
                ->get()
                ->groupBy('order_uuid');

            $touched = [];

            foreach ($lines as $orderUuid => $orderLines) {
                $prepaid = $prepaidByOrder[$orderUuid] ?? 0.0;

                foreach ($orderLines as $line) {
                    // Свободный остаток строки: то, что не закрыто прямым разнесением.
                    $free = round(max(0.0, (float) $line->amount - (float) $line->paid_amount), 2);
                    $offset = round(min($free, $prepaid), 2);
                    $prepaid = round(max(0.0, $prepaid - $offset), 2);

                    if (round((float) $line->prepaid_amount, 2) !== $offset) {
                        $line->forceFill(['prepaid_amount' => $offset])->saveQuietly();
                        $touched[] = $line->shipment_id;
                    }
                }
            }

            $this->refreshDueDates(array_unique($touched));
        }
    }

    /**
     * Суммы авансов по заказам — строки расшифровки с target_type = order.
     *
     * Берём из `payment_allocations`, а не из `orders.prepaid_amount`: заказа может
     * не быть на сайте (1С прислала платёж раньше документа), а деньги при этом
     * реальны. Возврат уменьшает аванс — та же логика, что у реализаций.
     *
     * @param  array<int, string>  $orderUuids
     * @return array<string, float>
     */
    private function prepaidOf(array $orderUuids): array
    {
        return DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereIn('pa.order_uuid', $orderUuids)
            ->where('pa.target_type', PaymentAllocation::TARGET_ORDER)
            ->whereNull('p.deleted_at')
            ->groupBy('pa.order_uuid')
            ->selectRaw('pa.order_uuid as order_uuid')
            ->selectRaw("SUM(CASE WHEN p.direction = 'out' THEN -pa.amount ELSE pa.amount END) as prepaid")
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->order_uuid => round(max(0.0, (float) $row->prepaid), 2),
            ])
            ->all();
    }

    /**
     * Пересчитать ближайшую плановую дату по нескольким реализациям.
     *
     * @param  array<int, int|null>  $shipmentIds
     */
    private function refreshDueDates(array $shipmentIds): void
    {
        $shipmentIds = array_values(array_unique(array_filter($shipmentIds)));

        if ($shipmentIds === []) {
            return;
        }

        Shipment::withoutGlobalScopes()->withTrashed()
            ->whereIn('id', $shipmentIds)
            ->get(['id', 'payment_due_date'])
            ->each(fn (Shipment $shipment) => $this->refreshDueDate($shipment));
    }

    /**
     * Ближайшая непогашенная плановая дата реализации.
     *
     * Строка считается закрытой, когда её сумма покрыта деньгами и авансом вместе:
     * клиенту без разницы, каким документом 1С закрыла долг.
     */
    private function refreshDueDate(Shipment $shipment): void
    {
        $nextDueDate = null;

        $lines = ShipmentPaymentSchedule::query()
            ->where('shipment_id', $shipment->id)
            ->inFifoOrder()
            ->get();

        foreach ($lines as $line) {
            $unpaid = (float) $line->amount - (float) $line->paid_amount - (float) $line->prepaid_amount;

            if ($unpaid > ShipmentPaymentSchedule::EPSILON) {
                $nextDueDate = $line->due_date;
                break;
            }
        }

        $this->applyDueDate($shipment, $nextDueDate);
    }

    /**
     * Пересчитать график сразу по нескольким реализациям.
     *
     * Вызывается из PaymentAllocationService: изменился факт оплаты — переехал план.
     *
     * @param  array<int, int|null>  $shipmentIds
     */
    public function redistributeMany(array $shipmentIds): void
    {
        $shipmentIds = array_values(array_unique(array_filter($shipmentIds)));

        if ($shipmentIds === []) {
            return;
        }

        foreach (array_chunk($shipmentIds, 500) as $chunk) {
            // Реализации без графика (1С его ещё не прислала) — большинство,
            // и гонять по ним транзакцию с блокировкой незачем.
            $withSchedule = ShipmentPaymentSchedule::query()
                ->whereIn('shipment_id', $chunk)
                ->distinct()
                ->pluck('shipment_id')
                ->all();

            if ($withSchedule === []) {
                continue;
            }

            Shipment::withoutGlobalScopes()->withTrashed()
                ->whereIn('id', $withSchedule)
                ->get(['id', 'uuid', 'paid_amount', 'payment_due_date'])
                ->each(fn (Shipment $shipment) => $this->redistribute($shipment));
        }
    }

    /**
     * Записать ближайшую плановую дату.
     *
     * saveQuietly + withoutEvents: пересчёт не должен дёргать Scout и обсерверы —
     * при первичной выгрузке это десятки тысяч лишних переиндексаций.
     */
    private function applyDueDate(Shipment $shipment, ?Carbon $nextDueDate): void
    {
        $current = $shipment->payment_due_date instanceof Carbon
            ? $shipment->payment_due_date->toDateString()
            : null;
        $next = $nextDueDate?->toDateString();

        if ($current === $next) {
            return;
        }

        Shipment::withoutEvents(function () use ($shipment, $next): void {
            $shipment->forceFill(['payment_due_date' => $next])->saveQuietly();
        });
    }

    /**
     * Дата из 1С. Мусор вместо даты — не повод ронять весь документ.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Латинский код из фиксированного набора.
     *
     * Незнакомый код обнуляется, а не сохраняется: на коды опирается логика,
     * и «почти правильное» значение молча ломало бы фильтры. Русское наименование
     * при этом сохраняется рядом и показывается как есть.
     *
     * @param  list<string>  $allowed
     */
    private function enumOrNull(mixed $value, array $allowed, string $field, Shipment $shipment): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null || in_array($value, $allowed, true)) {
            return $value;
        }

        Log::warning('PaymentScheduleService: неизвестный код в строке графика', [
            'shipment_uuid' => $shipment->uuid,
            'field' => $field,
            'value' => $value,
        ]);

        return null;
    }
}

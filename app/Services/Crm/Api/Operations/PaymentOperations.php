<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\ContractorBalance;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Shipment;
use App\Models\ShipmentPaymentSchedule;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\CrmEntityResolver;
use App\Support\Crm\CrmEntityMap;

/**
 * Платежи для машинного потребителя CRM.
 *
 * Реквизиты и разнесение ведёт 1С — операций записи здесь нет и быть не должно.
 * Скоуп тот же, что в вебе: набор партнёров задаёт актор, чужой платёж даёт 404.
 */
class PaymentOperations
{
    use ResolvesCrmEntities;

    public function __construct(
        private readonly CrmEntityResolver $resolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $clients = User::query()->visibleInCrm($actor)->select('users.id');

        $query = Payment::query()
            ->whereIn('user_id', $clients)
            ->with(['user:id,name,erp_name', 'company:id,name', 'allocations.shipment:id,number,erp_number']);

        if ($input->int('client_id')) {
            // Партнёра резолвим через скоуп: id вне скоупа не должен просто
            // отдавать пустой список — он должен давать 404, как в вебе.
            $client = $this->client($actor, $input, 'client_id');
            $query->where('user_id', $client->getKey());
        }

        if ($direction = $input->string('direction')) {
            $query->where('direction', $direction);
        }

        if ($dateFrom = $input->string('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }

        if ($dateTo = $input->string('date_to')) {
            $query->whereDate('date', '<=', $dateTo);
        }

        // Висящие авансы — частый вопрос менеджера: «за что партнёр заплатил,
        // но мы ещё не отгрузили».
        if ($input->bool('only_unallocated')) {
            $query->where('unallocated_amount', '>', Payment::EPSILON);
        }

        $perPage = min(max((int) ($input->int('per_page') ?: 25), 1), 100);

        $page = $query->orderByDesc('date')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($input->int('page') ?: 1)));

        return [
            'data' => collect($page->items())->map(fn (Payment $payment): array => $this->row($payment))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, OperationInput $input): array
    {
        /** @var Payment $payment */
        $payment = $this->resolver->resolveForActor($actor, CrmEntityMap::PAYMENT, (int) $input->int('payment'));

        $payment->load([
            'user:id,name,erp_name',
            'company:id,name,tax_id',
            'organization:id,name',
            'allocations.shipment:id,number,erp_number,date,total_amount,paid_amount,payment_status',
        ]);

        return array_merge($this->row($payment), [
            'document_type' => $payment->document_type,
            'operation_name' => $payment->operation_name,
            'operation_code' => $payment->operation_code,
            'bank_number' => $payment->bank_number,
            'bank_confirmed' => (bool) $payment->bank_confirmed,
            'uip' => $payment->uip,
            'purpose' => $payment->purpose,
            'organization' => $payment->organization?->name,
            'tax_id' => $payment->tax_id,
            'comment' => $payment->comment,
            'allocations' => $payment->allocations->map(fn (PaymentAllocation $allocation): array => [
                'amount' => (float) $allocation->amount,
                'shipment_uuid' => $allocation->shipment_uuid,
                'shipment_number' => $allocation->shipment?->erp_number ?: $allocation->shipment?->number,
                'shipment_id' => $allocation->shipment_id,
                'shipment_total' => $allocation->shipment ? (float) $allocation->shipment->total_amount : null,
                'shipment_paid' => $allocation->shipment ? (float) $allocation->shipment->paid_amount : null,
                'shipment_payment_status' => $allocation->shipment?->payment_status,
            ])->all(),
        ]);
    }

    /**
     * Неоплаченные и частично оплаченные реализации.
     *
     * Отдельная операция, а не «сходи в две»: это самый частый вопрос менеджера
     * к агенту, и собранный им самим JOIN по payment_allocations почти наверняка
     * посчитал бы возвраты как приход.
     *
     * @return array<string, mixed>
     */
    public function unpaidShipments(User $actor, OperationInput $input): array
    {
        $clients = User::query()->visibleInCrm($actor)->select('users.id');

        $query = Shipment::query()
            ->whereIn('user_id', $clients)
            ->whereIn('payment_status', [Shipment::PAYMENT_UNPAID, Shipment::PAYMENT_PARTIAL])
            ->with(['user:id,name,erp_name', 'company:id,name']);

        if ($input->int('client_id')) {
            $client = $this->client($actor, $input, 'client_id');
            $query->where('user_id', $client->getKey());
        }

        if ($dateFrom = $input->string('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }

        $perPage = min(max((int) ($input->int('per_page') ?: 25), 1), 100);

        $page = $query->orderBy('date')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($input->int('page') ?: 1)));

        return [
            'data' => collect($page->items())->map(fn (Shipment $shipment): array => [
                'id' => (int) $shipment->getKey(),
                'number' => $shipment->erp_number ?: $shipment->number,
                'date' => $shipment->date?->format('Y-m-d'),
                'client' => $shipment->user?->display_name,
                'client_id' => $shipment->user_id,
                'company' => $shipment->company?->name,
                'total_amount' => (float) $shipment->total_amount,
                'paid_amount' => (float) $shipment->paid_amount,
                'unpaid_amount' => $shipment->unpaid_amount,
                'payment_status' => $shipment->payment_status,
                'payment_status_label' => $shipment->payment_status_label,
                'currency_code' => $shipment->currency_code,
            ])->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'notes' => [
                    'Это остаток по документам, а НЕ долг партнёра. 1С закрывает долг не только '
                        .'платежами по накладным (авансы по заказам, зачёты, корректировки), поэтому '
                        .'сумма здесь бывает существенно больше реальной задолженности.',
                    'На вопрос «сколько партнёр должен» отвечайте по `payment.balances` — это данные 1С.',
                ],
            ],
        ];
    }

    /**
     * Ожидаемые поступления по графику оплаты за период.
     *
     * Отдельная операция, а не «посчитай сам по отгрузкам»: неоплаченный остаток
     * отгрузки и остаток по конкретной плановой дате — разные величины. Отгрузка
     * с рассрочкой на три платежа должна попадать в календарь тремя строками
     * в разные месяцы, а не одной суммой на дату документа.
     *
     * @return array<string, mixed>
     */
    public function schedule(User $actor, OperationInput $input): array
    {
        $clients = User::query()->visibleInCrm($actor)->select('users.id');

        $query = ShipmentPaymentSchedule::query()
            ->whereHas('shipment', function ($shipment) use ($clients, $actor, $input): void {
                $shipment->whereIn('user_id', $clients);

                if ($input->int('client_id')) {
                    $shipment->where('user_id', $this->client($actor, $input, 'client_id')->getKey());
                }
            })
            // Закрытые строки — уже полученные деньги: в «сколько ждём» им не место.
            ->outstanding()
            ->with(['shipment:id,number,erp_number,date,currency_code,user_id', 'shipment.user:id,name,erp_name']);

        if ($dateFrom = $input->string('date_from')) {
            $query->whereDate('due_date', '>=', $dateFrom);
        }

        if ($dateTo = $input->string('date_to')) {
            $query->whereDate('due_date', '<=', $dateTo);
        }

        if ($input->bool('only_overdue')) {
            $query->whereDate('due_date', '<', now()->toDateString());
        }

        $perPage = min(max((int) ($input->int('per_page') ?: 25), 1), 100);

        $page = $query->inFifoOrder()
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($input->int('page') ?: 1)));

        return [
            'data' => collect($page->items())->map(fn (ShipmentPaymentSchedule $line): array => [
                'id' => (int) $line->getKey(),
                'due_date' => $line->due_date?->format('Y-m-d'),
                'is_overdue' => $line->is_overdue,
                'amount' => (float) $line->amount,
                'paid_amount' => (float) $line->paid_amount,
                'unpaid_amount' => $line->unpaid_amount,
                'status' => $line->status,
                'stage' => $line->stage,
                'stage_name' => $line->stage_name,
                'currency_code' => $line->shipment?->currency_code,
                'shipment_id' => $line->shipment_id,
                'shipment_number' => $line->shipment?->erp_number ?: $line->shipment?->number,
                'client' => $line->shipment?->user?->display_name,
                'client_id' => $line->shipment?->user_id,
            ])->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /**
     * Сальдо взаиморасчётов и просроченная задолженность — как их посчитала 1С.
     *
     * ЭТО МАСТЕР-ДАННЫЕ. На вопрос «сколько партнёр должен» отвечать нужно отсюда,
     * а не суммированием остатков по документам: 1С закрывает долг не только
     * платежами по накладным (авансы по заказам, зачёты, корректировки), и сумма
     * по документам систематически оказывается больше реального долга.
     *
     * Строка — контрагент, а не партнёр: 1С ведёт расчёты по юрлицам, и у одного
     * партнёра их бывает несколько. Итог по партнёру — сумма его контрагентов.
     *
     * @return array<string, mixed>
     */
    public function balances(User $actor, OperationInput $input): array
    {
        $clients = User::query()->visibleInCrm($actor)->select('users.id');

        $query = ContractorBalance::query()
            ->whereIn('user_id', $clients)
            ->with(['user:id,name,erp_name', 'company:id,name']);

        if ($input->int('client_id')) {
            $query->where('user_id', $this->client($actor, $input, 'client_id')->getKey());
        }

        if ($input->bool('only_overdue')) {
            $query->where('overdue_debt', '>', 0);
        }

        $perPage = min(max((int) ($input->int('per_page') ?: 25), 1), 100);

        $page = $query->orderByDesc('overdue_debt')
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($input->int('page') ?: 1)));

        return [
            'data' => collect($page->items())->map(fn (ContractorBalance $balance): array => [
                'id' => (int) $balance->getKey(),
                'client' => $balance->user->display_name,
                'client_id' => $balance->user_id,
                'contractor' => $balance->company?->name,
                'tax_id' => $balance->tax_id,
                // Отрицательное сальдо — долг партнёра. Знак повторяет 1С, чтобы
                // число можно было сверить с учётной системой не задумываясь.
                'current_balance' => (float) $balance->current_balance,
                'overdue_debt' => (float) $balance->overdue_debt,
                'currency_code' => 'RUB',
                'erp_updated_at' => $balance->balance_erp_updated_at?->format('Y-m-d H:i'),
            ])->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'notes' => [
                    'Мастер-данные: так долг видит 1С. Это ответ на «сколько партнёр должен».',
                    'Отрицательное сальдо — долг партнёра, положительное — переплата или аванс.',
                    'Строка — контрагент (юрлицо). У партнёра их может быть несколько, итог по партнёру — их сумма.',
                    'Валюта в 1С не передаётся: суммы подписаны как рубли. Для мультивалютного контрагента итог будет неточным.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Payment $payment): array
    {
        return [
            'id' => (int) $payment->getKey(),
            'number' => $payment->number,
            'date' => $payment->date->format('Y-m-d H:i'),
            'direction' => $payment->direction,
            'direction_label' => $payment->direction_label,
            'amount' => (float) $payment->amount,
            'currency_code' => $payment->currency_code,
            'allocated_amount' => (float) $payment->allocated_amount,
            'unallocated_amount' => (float) $payment->unallocated_amount,
            'allocation_status' => $payment->allocation_status,
            'client' => $payment->user?->display_name,
            'client_id' => $payment->user_id,
            'company' => $payment->company?->name,
            'shipments' => $payment->allocations
                ->map(fn (PaymentAllocation $allocation): ?string => $allocation->shipment?->erp_number
                    ?: $allocation->shipment?->number)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }
}

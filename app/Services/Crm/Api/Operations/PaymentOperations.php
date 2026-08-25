<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\Payment;
use App\Models\PaymentAllocation;
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

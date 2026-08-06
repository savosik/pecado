<?php

namespace App\Services\Erp\Handlers;

use App\Models\Payment;
use App\Services\Erp\Support\PaymentPayloadMapper;
use App\Services\Erp\Support\ResolvesContractorParty;
use App\Services\Erp\Support\ResolvesDocumentOrganization;
use App\Services\Payments\PaymentAllocationService;
use Illuminate\Support\Facades\Log;

/**
 * Обработка события payment.created из 1С (US-17, протокол v15.11.0).
 *
 * Создаёт или обновляет платёж по UUID — идемпотентно, включая восстановление
 * мягко удалённого документа (1С отменила проведение, затем провела снова).
 *
 * Расшифровка платежа приходит массивом `allocations` внутри самого сообщения
 * и заменяется целиком. Отдельного события для неё нет.
 */
class HandlePaymentCreated
{
    use ResolvesContractorParty;
    use ResolvesDocumentOrganization;

    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandlePaymentCreated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $contractorUuid = $payload['contractor_uuid'] ?? null;
        $taxId = $payload['tax_id'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;

        [$companyId, $userId] = $this->resolveCompanyAndUser($contractorUuid, $taxId, $partnerUuid);

        $fields = PaymentPayloadMapper::fields($payload) + [
            'company_id' => $companyId,
            'user_id' => $userId,
        ];

        // Организация-получатель. Отсутствие поля не сбрасывает сохранённое значение —
        // 1С может не уметь его присылать. Склад у платежа отсутствует по смыслу,
        // поэтому из общего трейта берём только organization_id.
        $organization = $this->resolveOrganizationFields($payload, 'HandlePaymentCreated');
        unset($organization['warehouse_id']);
        $fields = array_merge($fields, $organization);

        $payment = Payment::withTrashed()->where('uuid', $uuid)->first();

        if ($payment) {
            if ($payment->trashed()) {
                $payment->restore();
            }
            $payment->update($fields);
        } else {
            $payment = Payment::create($fields + ['uuid' => $uuid]);
        }

        $service = app(PaymentAllocationService::class);

        // Ключа нет — разнесение не трогаем (правило v15.11.0). Пустой массив
        // очищает его: это разные операции, различие описано в контракте.
        if (array_key_exists('allocations', $payload) && is_array($payload['allocations'])) {
            $service->sync($payment, $payload['allocations']);
        } else {
            // Сумма могла измениться без изменения расшифровки — аванс пересчитываем всегда.
            $service->recalculatePayment($payment);
        }

        Log::info('HandlePaymentCreated: платёж создан/обновлён', [
            'uuid' => $uuid,
            'company_id' => $companyId,
            'user_id' => $userId,
            'allocations' => isset($payload['allocations']) ? count($payload['allocations']) : 'не присланы',
        ]);
    }
}

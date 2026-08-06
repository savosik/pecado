<?php

namespace App\Services\Erp\Handlers;

use App\Models\Payment;
use App\Services\Erp\ErpHandlerOutcome;
use App\Services\Erp\Support\PaymentPayloadMapper;
use App\Services\Erp\Support\ResolvesContractorParty;
use App\Services\Erp\Support\ResolvesDocumentOrganization;
use App\Services\Payments\PaymentAllocationService;
use Illuminate\Support\Facades\Log;

/**
 * Обработка события payment.updated из 1С (US-17, протокол v15.11.0).
 *
 * Отличие от HandleShipmentUpdated: неизвестный документ здесь **создаётся**,
 * а не игнорируется. Структура `updated` полная, и потерянный `payment.created`
 * означал бы потерянные деньги в отчётах — тогда как потерянная реализация
 * хотя бы видна в заказах. Факт достройки помечается как `recovered`,
 * чтобы он был заметен в логе шины, а не растворился в успехах.
 */
class HandlePaymentUpdated
{
    use ResolvesContractorParty;
    use ResolvesDocumentOrganization;

    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandlePaymentUpdated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $payment = Payment::withTrashed()->where('uuid', $uuid)->first();

        $contractorUuid = $payload['contractor_uuid'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;
        $taxId = array_key_exists('tax_id', $payload) ? $payload['tax_id'] : $payment?->tax_id;

        $fields = PaymentPayloadMapper::fields($payload);

        $organization = $this->resolveOrganizationFields($payload, 'HandlePaymentUpdated');
        unset($organization['warehouse_id']);
        $fields = array_merge($fields, $organization);

        // Пере-резолв контрагента только когда 1С прислала, по чему резолвить.
        // Найденное не затираем null-ом: контрагент мог приехать позже платежа,
        // и потерять уже установленную связь хуже, чем не обновить её.
        if ($contractorUuid || array_key_exists('tax_id', $payload) || $partnerUuid) {
            [$companyId, $userId] = $this->resolveCompanyAndUser($contractorUuid, $taxId, $partnerUuid);

            if ($companyId !== null) {
                $fields['company_id'] = $companyId;
            }
            if ($userId !== null) {
                $fields['user_id'] = $userId;
            }
        }

        if (! $payment) {
            $payment = Payment::create($fields + ['uuid' => $uuid]);

            app(ErpHandlerOutcome::class)->markRecovered(
                'payment.updated пришёл на неизвестный платёж — документ достроен из payload'
            );

            Log::warning('HandlePaymentUpdated: платёж не найден, создан из payload', ['uuid' => $uuid]);
        } else {
            if ($payment->trashed()) {
                $payment->restore();
            }
            $payment->update($fields);
        }

        $service = app(PaymentAllocationService::class);

        // Ключа нет — разнесение не трогаем; пустой массив очищает его целиком.
        // Различие зафиксировано в контракте: 1С обязана присылать расшифровку
        // целиком, а не досылать изменившиеся строки.
        if (array_key_exists('allocations', $payload) && is_array($payload['allocations'])) {
            $service->sync($payment, $payload['allocations']);
        } else {
            $service->recalculatePayment($payment);
        }

        Log::info('HandlePaymentUpdated: платёж обновлён', [
            'uuid' => $uuid,
            'allocations' => isset($payload['allocations']) ? count($payload['allocations']) : 'не присланы',
        ]);
    }
}

<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\Shipment;
use App\Services\Erp\Support\ReadsShipmentInvoice;
use App\Services\Erp\Support\ResolvesContractorParty;
use App\Services\Erp\Support\ResolvesDocumentOrganization;
use Illuminate\Support\Facades\Log;

class HandleShipmentUpdated
{
    use ReadsShipmentInvoice;
    use ResolvesContractorParty;
    use ResolvesDocumentOrganization;

    /**
     * Обработка события shipment.updated из 1С.
     * v3: отправляется при перепроведении или изменении реализации.
     * Обновляет статус реализации и позиции по UUID.
     *
     * v13.2: при смене tax_id или появлении contractor_uuid в payload —
     * пере-резолвит Company с приоритетом UUID и fallback на tax_id + user_id.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleShipmentUpdated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $shipment = Shipment::where('uuid', $uuid)->first();

        if (! $shipment) {
            Log::info('HandleShipmentUpdated: реализация не найдена', ['uuid' => $uuid]);

            return;
        }

        // Обновление статуса
        if (isset($payload['status'])) {
            $shipment->status = $payload['status'];
        }

        // Обновление даты
        if (isset($payload['date'])) {
            $shipment->date = $payload['date'];
        }

        // Обновление валюты
        if (isset($payload['currency_code'])) {
            $shipment->currency_code = $payload['currency_code'];
        }

        // Обновление номера
        if (isset($payload['number'])) {
            $shipment->number = $payload['number'];
            $shipment->erp_number = $payload['number'];
        }

        // v15.16.0: счёт-фактура. Отсутствие ключа не сбрасывает сохранённое —
        // 1С может присылать реквизит не по всем документам.
        foreach ($this->invoiceFields($payload) as $field => $value) {
            $shipment->{$field} = $value;
        }

        // v13.7: аудит-метки 1С. array_key_exists, чтобы передача null
        // тоже считалась явной операцией; отсутствие ключа — БД не трогаем.
        // TZ-нормализация — в App\Casts\ErpDatetime.
        if (array_key_exists('erp_created_at', $payload)) {
            $shipment->erp_created_at = $payload['erp_created_at'];
        }
        if (array_key_exists('erp_updated_at', $payload)) {
            $shipment->erp_updated_at = $payload['erp_updated_at'];
        }

        // Обновление tax_id + пере-резолв Company (v13.2)
        $contractorUuid = $payload['contractor_uuid'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;
        $taxId = array_key_exists('tax_id', $payload) ? $payload['tax_id'] : $shipment->tax_id;

        if ($contractorUuid || array_key_exists('tax_id', $payload)) {
            [$companyId, $userId] = $this->resolveCompanyAndUser($contractorUuid, $taxId, $partnerUuid);
            if ($companyId !== null) {
                $shipment->company_id = $companyId;
            }
            if ($userId !== null) {
                $shipment->user_id = $userId;
            }
            if (array_key_exists('tax_id', $payload)) {
                $shipment->tax_id = $taxId;
            }
        }

        // v15.8.0: организация и склад проведения. Отсутствие поля не сбрасывает
        // сохранённое значение.
        foreach ($this->resolveOrganizationFields($payload, 'HandleShipmentUpdated') as $field => $value) {
            $shipment->{$field} = $value;
        }

        $shipment->save();

        // Обновление позиций (если переданы)
        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->syncItems($shipment, $payload['items']);
        }

        // v15.5: пересчёт списания партий некондиции (количество могло измениться).
        app(\App\Services\Defect\DefectShipmentService::class)->reconcileForShipment($shipment);

        // Оплату считает регистр (см. HandleShipmentCreated) — доклейки
        // расшифровок и графика из payload сняты в fin-11.

        Log::info('HandleShipmentUpdated: реализация обновлена', [
            'uuid' => $uuid,
            'status' => $payload['status'] ?? 'не изменён',
        ]);
    }

    /**
     * Синхронизация позиций реализации (v2 — с дополнительными полями).
     * Заменяет позиции на полученные из 1С.
     */
    private function syncItems(Shipment $shipment, array $items): void
    {
        $shipment->items()->delete();

        $totalAmount = 0;

        foreach ($items as $item) {
            $product = Product::withoutGlobalScopes()->where('external_id', $item['product_uuid'] ?? '')->first();

            $quantity = $item['quantity'] ?? 0;
            $price = $item['price'] ?? 0;
            $autoDiscount = $item['auto_discount_percent'] ?? 0;
            $manualDiscount = $item['manual_discount_percent'] ?? 0;
            $vatRate = $item['vat_rate'] ?? null;
            $orderUuid = $item['order_uuid'] ?? null;

            // total — итоговая сумма из 1С (v2); если нет — вычисляем
            $total = isset($item['total']) ? (float) $item['total'] : round($quantity * $price, 2);
            $subtotal = round($quantity * $price, 2);

            $shipment->items()->create([
                'product_id' => $product?->id,
                'order_uuid' => $orderUuid,
                'quantity' => $quantity,
                'price' => $price,
                'auto_discount_percent' => $autoDiscount,
                'manual_discount_percent' => $manualDiscount,
                'total' => $total,
                'subtotal' => $subtotal,
                'vat_rate' => $vatRate,
            ]);

            $totalAmount += $total;
        }

        $shipment->total_amount = $totalAmount;
        $shipment->save();
    }
}

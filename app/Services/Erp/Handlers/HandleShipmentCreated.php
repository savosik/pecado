<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\Shipment;
use App\Services\Erp\Support\LinksOverdueDetailsToShipment;
use App\Services\Erp\Support\LinksPaymentAllocationsToShipment;
use App\Services\Erp\Support\ResolvesContractorParty;
use App\Services\Erp\Support\ResolvesDocumentOrganization;
use Illuminate\Support\Facades\Log;

class HandleShipmentCreated
{
    use LinksOverdueDetailsToShipment;
    use LinksPaymentAllocationsToShipment;
    use ResolvesContractorParty;
    use ResolvesDocumentOrganization;

    /**
     * Обработка события shipment.created из 1С.
     * v3: отправляется при первом проведении реализации.
     * Создаёт или обновляет реализацию по UUID (идемпотентно).
     *
     * Матчинг Company (v13.2):
     *  1. По erp_id = contractor_uuid (глобально уникальный, приоритет).
     *  2. Fallback: по tax_id + user_id (резолв через partner_uuid). Без user_id
     *     fallback не выполняется — иначе можно найти чужую Company с тем же ИНН.
     *  3. Если Company не найдена — shipment сохраняется с company_id = null.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleShipmentCreated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $contractorInn = $payload['tax_id'] ?? null;
        $contractorUuid = $payload['contractor_uuid'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;

        [$companyId, $userId] = $this->resolveCompanyAndUser($contractorUuid, $contractorInn, $partnerUuid);

        $fields = [
            'number' => $payload['number'] ?? null,
            'erp_number' => $payload['number'] ?? null,
            'user_id' => $userId,
            'company_id' => $companyId,
            'tax_id' => $contractorInn,
            'date' => $payload['date'] ?? null,
            'status' => $payload['status'] ?? 'new',
            'currency_code' => $payload['currency_code'] ?? null,
        ];

        // v13.7: аудит-метки 1С (опционально). Передача null — явная установка,
        // отсутствие ключа — не трогаем существующее значение в БД.
        // TZ-нормализация — в App\Casts\ErpDatetime.
        if (array_key_exists('erp_created_at', $payload)) {
            $fields['erp_created_at'] = $payload['erp_created_at'];
        }
        if (array_key_exists('erp_updated_at', $payload)) {
            $fields['erp_updated_at'] = $payload['erp_updated_at'];
        }

        // v15.8.0: организация и склад проведения. Отсутствие поля не сбрасывает
        // сохранённое значение — 1С может не уметь его присылать.
        $fields = array_merge($fields, $this->resolveOrganizationFields($payload, 'HandleShipmentCreated'));

        $shipment = Shipment::withTrashed()->where('uuid', $uuid)->first();

        if ($shipment) {
            if ($shipment->trashed()) {
                $shipment->restore();
            }
            $shipment->update($fields);
        } else {
            $shipment = Shipment::create($fields + ['uuid' => $uuid]);
        }

        // Синхронизация позиций
        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->syncItems($shipment, $payload['items']);
        }

        // v15.5: списание партий некондиции, если реализация относится к заказу уценки.
        app(\App\Services\Defect\DefectShipmentService::class)->reconcileForShipment($shipment);

        // Баланс с этой реализацией мог прийти раньше документа — связываем.
        $this->linkOverdueDetails($shipment, 'HandleShipmentCreated');

        // v15.11.0: платежи идут своей очередью, их расшифровка регулярно
        // приезжает раньше реализации — доклеиваем и пересчитываем оплату.
        $this->linkPaymentAllocations($shipment, 'HandleShipmentCreated');

        Log::info('HandleShipmentCreated: реализация создана/обновлена', [
            'uuid' => $uuid,
            'company_id' => $companyId,
            'organization_id' => $fields['organization_id'] ?? 'не прислана',
            'warehouse_id' => $fields['warehouse_id'] ?? 'не прислан',
        ]);
    }

    /**
     * Синхронизация позиций реализации (v2 — с дополнительными полями).
     */
    private function syncItems(Shipment $shipment, array $items): void
    {
        // Удаляем старые позиции
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

            // total — итоговая сумма, приходит из 1С (v2); если нет — вычисляем
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

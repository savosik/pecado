<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\Product;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;

class HandleShipmentCreated
{
    /**
     * Обработка события shipment.created из 1С.
     * v3: отправляется при первом проведении реализации.
     * Создаёт или обновляет реализацию по UUID (идемпотентно).
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleShipmentCreated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $contractorInn = $payload['tax_id'] ?? null;
        $companyId = null;
        $userId = null;

        // Поиск компании по ИНН контрагента
        if ($contractorInn) {
            $company = Company::withoutGlobalScopes()
                ->where('tax_id', $contractorInn)
                ->first();

            if ($company) {
                $companyId = $company->id;
                $userId = $company->user_id;
            }
        }

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

        Log::info('HandleShipmentCreated: реализация создана/обновлена', [
            'uuid' => $uuid,
            'company_id' => $companyId,
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

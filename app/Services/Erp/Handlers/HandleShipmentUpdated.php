<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class HandleShipmentUpdated
{
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

        $shipment->save();

        // Обновление позиций (если переданы)
        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->syncItems($shipment, $payload['items']);
        }

        Log::info('HandleShipmentUpdated: реализация обновлена', [
            'uuid' => $uuid,
            'status' => $payload['status'] ?? 'не изменён',
        ]);
    }

    /**
     * Резолв Company + User по приоритету: contractor_uuid → tax_id + user_id.
     *
     * @return array{0: ?int, 1: ?int} [company_id, user_id]
     */
    private function resolveCompanyAndUser(?string $contractorUuid, ?string $taxId, ?string $partnerUuid): array
    {
        $userId = null;
        if ($partnerUuid) {
            $userId = User::where('erp_id', $partnerUuid)->value('id');
        }

        $company = null;

        if ($contractorUuid) {
            $company = Company::withoutGlobalScopes()
                ->where('erp_id', $contractorUuid)
                ->first();
        }

        if (! $company && $taxId && $userId) {
            $company = Company::withoutGlobalScopes()
                ->where('user_id', $userId)
                ->where('tax_id', $taxId)
                ->first();

            if ($company && $contractorUuid && ! $company->erp_id) {
                Company::withoutEvents(function () use ($company, $contractorUuid) {
                    $company->update(['erp_id' => $contractorUuid]);
                });
            }
        }

        if ($company) {
            return [$company->id, $company->user_id ?? $userId];
        }

        return [null, $userId];
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

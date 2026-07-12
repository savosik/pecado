<?php

namespace App\Listeners;

use App\Jobs\PublishOrderToErpJob;
use Illuminate\Support\Str;

class PublishOrderToErp
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if (! isset($event->order)) {
            return;
        }

        $order = $event->order;

        if ($order->fromErp) {
            return;
        }

        // Load relationships to include in the payload
        $order->load(['items.product', 'user', 'company.bankAccounts', 'user.region']);

        $eventName = match (class_basename($event)) {
            'OrderCreated' => 'order.created',
            'OrderUpdated' => 'order.updated',
            'OrderDeleted' => 'order.deleted',
            default => strtolower(class_basename($event)),
        };

        $payload = [
            'event' => $eventName,
            'message_id' => 'msg-'.Str::uuid()->toString(),
            'uuid' => $order->uuid,
            'number' => $order->number,
            'date' => $order->created_at->toIso8601String(),
            'status' => $order->status?->value ?? $order->status,
            'type' => $order->type?->value ?? $order->type ?? 'order',
            'partner_uuid' => $order->user?->erp_id,
            'warehouse_uuids' => $this->resolveWarehouseUuids($order),
            'comment' => $order->comment,
            'manager_comment' => $order->manager_comment ?: null,
            'warehouse_comment' => $order->warehouse_comment ?: null,
            'delivery_address' => $order->delivery_address,
            // v15.3: способ доставки — delivery | pickup (отсутствие = delivery)
            'delivery_method' => $order->delivery_method?->value ?? 'delivery',
            'timestamp' => now()->toIso8601String(),
        ];

        // Данные контрагента (v13.2: приоритет UUID, fallback на ИНН)
        if ($order->company) {
            $company = $order->company;
            $payload['contractor'] = [
                'uuid' => $company->erp_id,
                'country' => $company->country?->value ?? $company->country,
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'registration_number' => $company->registration_number,
                'tax_code' => $company->tax_code,
                'okpo_code' => $company->okpo_code,
                'legal_address' => $company->legal_address,
                'actual_address' => $company->actual_address,
                'phone' => $company->phone,
                'email' => $company->email,
                'bank_accounts' => $company->bankAccounts->map(function ($account) {
                    return [
                        'bank_name' => $account->bank_name,
                        'bank_bik' => $account->bank_bik,
                        'correspondent_account' => $account->correspondent_account,
                        'account_number' => $account->account_number,
                        'is_primary' => $account->is_primary,
                    ];
                })->toArray(),
            ];
        }

        // Валюта и курс
        $payload['currency_code'] = $order->currency_code;
        $payload['exchange_rate'] = (float) $order->exchange_rate;
        $payload['rate_coefficient'] = (float) ($order->rate_coefficient ?? 1.0);

        // Позиции заказа (v7: base_price, discount_percent, final_price)
        $payload['items'] = $order->items->map(function ($item) {
            return [
                'product_uuid' => $item->product?->external_id,
                'quantity' => $item->quantity,
                'base_price' => (float) ($item->base_price ?? $item->price),
                'discount_percent' => (float) ($item->discount_percent ?? 0),
                'final_price' => (float) ($item->final_price ?? $item->price),
            ];
        })->toArray();

        PublishOrderToErpJob::dispatch($payload);
    }

    /**
     * Resolve warehouse_uuids for the order.
     *
     * For 'order' type → primary warehouses of user's region.
     * For 'preorder' type → preorder warehouses of user's region.
     * Falls back to empty array if region is not set.
     *
     * @return string[]
     */
    private function resolveWarehouseUuids(\App\Models\Order $order): array
    {
        $region = $order->user?->region;

        if (! $region) {
            return [];
        }

        $type = $order->type?->value ?? $order->type ?? 'order';

        $warehouses = match ($type) {
            'preorder' => $region->preorderWarehouses()->get(),
            default => $region->primaryWarehouses()->get(),
        };

        $uuids = $warehouses
            ->pluck('external_id')
            ->filter()
            ->values()
            ->toArray();

        // ⚠️ КОСТЫЛЬ: только для предзаказов подменяем UUID склада «Тюмень Основной».
        // См. config('erp.preorder_warehouse_uuid_override'). Легко откатывается
        // флагом PREORDER_WAREHOUSE_UUID_OVERRIDE_ENABLED=false.
        if ($type === 'preorder') {
            $uuids = $this->applyPreorderWarehouseOverride($uuids);
        }

        return $uuids;
    }

    /**
     * ⚠️ ВРЕМЕННЫЙ КОСТЫЛЬ: подмена UUID склада «Тюмень Основной» в предзаказах.
     *
     * По требованию 1С в исходящих preorder-сообщениях UUID склада
     * «Тюмень Основной» (source_uuid) временно заменяется на target_uuid.
     * Управляется через config('erp.preorder_warehouse_uuid_override').
     *
     * Откат: PREORDER_WAREHOUSE_UUID_OVERRIDE_ENABLED=false либо удалить этот
     * метод вместе с его вызовом и блоком конфига.
     *
     * @param  string[]  $uuids
     * @return string[]
     */
    private function applyPreorderWarehouseOverride(array $uuids): array
    {
        $override = config('erp.preorder_warehouse_uuid_override');

        if (! ($override['enabled'] ?? false)) {
            return $uuids;
        }

        $source = $override['source_uuid'] ?? null;
        $target = $override['target_uuid'] ?? null;

        if (! $source || ! $target) {
            return $uuids;
        }

        return array_values(array_map(
            static fn (string $uuid): string => $uuid === $source ? $target : $uuid,
            $uuids,
        ));
    }
}

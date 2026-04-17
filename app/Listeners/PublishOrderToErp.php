<?php

namespace App\Listeners;

use App\Jobs\PublishOrderToErpJob;

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
            'uuid' => $order->uuid,
            'number' => $order->number,
            'date' => $order->created_at->toIso8601String(),
            'status' => $order->status?->value ?? $order->status,
            'type' => $order->type?->value ?? $order->type ?? 'order',
            'partner_uuid' => $order->user?->erp_id,
            'warehouse_uuids' => $this->resolveWarehouseUuids($order),
            'comment' => $order->comment,
            'delivery_address' => $order->delivery_address,
            'timestamp' => now()->toIso8601String(),
        ];

        // Данные контрагента (US-06: сопоставление в 1С по ИНН)
        if ($order->company) {
            $company = $order->company;
            $payload['contractor'] = [
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

        return $warehouses
            ->pluck('external_id')
            ->filter()
            ->values()
            ->toArray();
    }
}

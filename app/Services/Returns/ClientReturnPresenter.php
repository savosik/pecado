<?php

namespace App\Services\Returns;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Models\ProductReturn;

/**
 * Представление возврата для клиента — одно для кабинета и API v1.
 */
class ClientReturnPresenter
{
    /**
     * Строка списка.
     *
     * @return array<string, mixed>
     */
    public function row(ProductReturn $return, bool $iso = false): array
    {
        return [
            'id' => $return->id,
            'number' => $return->erp_number ?? ('#'.$return->id),
            'uuid' => $return->uuid,
            'status' => $return->status?->value,
            'status_label' => $this->statusLabel($return->status),
            'total_amount' => $iso ? round((float) $return->total_amount, 2) : $return->total_amount,
            'created_at' => $iso ? $return->created_at?->toIso8601String() : $return->created_at?->format('d.m.Y H:i'),
            'items_count' => $return->items->count(),
            'primary_reason' => $return->items->first()?->reason?->value,
            'primary_reason_label' => $this->reasonLabel($return->items->first()?->reason),
        ];
    }

    /**
     * Карточка с составом, основаниями и продавцом.
     *
     * @return array<string, mixed>
     */
    public function card(ProductReturn $return, bool $iso = false): array
    {
        // is_stub обязателен: у заглушки вместо названия лежит UUID, клиенту его не показываем
        $return->loadMissing([
            'items.product',
            'items.shipmentItem.shipment',
            'organization:id,name,legal_name,tax_id,is_stub',
        ]);

        $date = fn ($value) => $value === null ? null : ($iso ? $value->toIso8601String() : $value->format('d.m.Y H:i'));

        return [
            'id' => $return->id,
            'number' => $return->erp_number ?? ('#'.$return->id),
            'uuid' => $return->uuid,
            'status' => $return->status?->value,
            'status_label' => $this->statusLabel($return->status),
            'total_amount' => $iso ? round((float) $return->total_amount, 2) : $return->total_amount,
            'comment' => $return->comment,
            'created_at' => $date($return->created_at),
            'updated_at' => $date($return->updated_at),
            'seller' => $this->seller($return),
            'items' => $return->items->map(function ($item) use ($iso) {
                $shipment = $item->shipmentItem?->shipment;

                return [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'subtotal' => $item->subtotal,
                    'reason' => $item->reason?->value,
                    'reason_label' => $this->reasonLabel($item->reason),
                    'reason_comment' => $item->reason_comment,
                    'product' => $item->product ? [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'sku' => $item->product->sku,
                        'slug' => $item->product->slug,
                        'image_url' => $item->product->getFirstMediaUrl('main'),
                    ] : null,
                    'shipment' => $shipment ? [
                        'id' => $shipment->id,
                        'uuid' => $shipment->uuid,
                        'number' => $shipment->number,
                        'date' => $iso ? $shipment->date?->toDateString() : $shipment->date?->format('d.m.Y'),
                        'currency_code' => $shipment->currency_code,
                    ] : null,
                ];
            })->values()->all(),
        ];
    }

    public function statusLabel(?ReturnStatus $status): string
    {
        return $status?->label() ?? 'Неизвестно';
    }

    public function reasonLabel(?ReturnReason $reason): string
    {
        return match ($reason) {
            ReturnReason::DEFECTIVE => 'Бракованный товар',
            ReturnReason::WRONG_ITEM => 'Неправильный товар',
            ReturnReason::CHANGED_MIND => 'Передумал',
            ReturnReason::DAMAGED_IN_TRANSIT => 'Повреждён при доставке',
            ReturnReason::WRONG_SIZE => 'Неправильный размер',
            ReturnReason::OTHER => 'Другое',
            default => 'Не указано',
        };
    }

    /**
     * Организация возврата — справочно, выведена с реализаций-оснований.
     * `null`, когда выключен флаг, организация не определена либо это заглушка.
     *
     * @return array<string, mixed>|null
     */
    public function seller(ProductReturn $return): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $return->organization;

        if (! $organization || $organization->is_stub) {
            return null;
        }

        return [
            'name' => $organization->name,
            'legal_name' => $organization->legal_name,
            'tax_id' => $organization->tax_id,
        ];
    }
}

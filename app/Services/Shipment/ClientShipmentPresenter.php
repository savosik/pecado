<?php

namespace App\Services\Shipment;

use App\Models\Currency;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\Currency\CabinetAmountConverter;
use App\Support\Payments\PaymentSchedulePresenter;
use Illuminate\Support\Collection;

/**
 * Представление реализации для клиента.
 *
 * Три потребителя: legacy `/api/client-api` (форма зафиксирована контрактом
 * и не меняется ни на байт — {@see payload()}), API v1 (та же форма плюс
 * продавец — {@see row()}, {@see card()}) и кабинет (пересчёт в валюту клиента,
 * даты в формате экрана — {@see cabinetRow()}, {@see cabinetCard()}).
 *
 * Блок оплаты во всех формах появляется только при открытых финансах: остаток
 * по документу не сверен с 1С, и клиенту его показывать нельзя.
 */
class ClientShipmentPresenter
{
    public function __construct(private readonly CabinetAmountConverter $amounts) {}

    /**
     * Форма legacy `/api/client-api/{token}/shipments` — контракт интеграций.
     *
     * @return array<string, mixed>
     */
    public function payload(Shipment $shipment, bool $finance, bool $withItems): array
    {
        $payload = [
            'id' => $shipment->id,
            'uuid' => $shipment->uuid,
            'number' => $this->number($shipment),
            'erp_number' => $shipment->erp_number,
            'date' => $shipment->date?->toDateString(),
            'status' => $shipment->status,
            'status_label' => $shipment->status_label,
            'currency_code' => $shipment->currency_code ?? 'RUB',
            'total_amount' => round((float) $shipment->total_amount, 2),
            'items_count' => (int) ($shipment->items_count ?? $shipment->items->count()),
            // Печатный номер счёта-фактуры: клиент сверяет документ по бумаге.
            'invoice_number' => $shipment->invoice_number_display ?: $shipment->invoice_number,
            'invoice_date' => $shipment->invoice_date?->toDateString(),
            'tax_id' => $shipment->tax_id,
            'company' => $shipment->company ? [
                'id' => $shipment->company->id,
                'name' => $shipment->company->name,
                'legal_name' => $shipment->company->legal_name,
                'inn' => $shipment->company->tax_id,
            ] : null,
            // updated_at — время последнего изменения на сайте, по нему же
            // работает фильтр updated_since; erp_updated_at — время из 1С.
            'updated_at' => $shipment->updated_at?->toIso8601String(),
            'erp_updated_at' => $shipment->erp_updated_at?->toIso8601String(),
        ];

        if ($finance) {
            $payload += [
                'payment_status' => $shipment->payment_status,
                'payment_status_label' => $shipment->payment_status_label,
                'paid_amount' => round((float) $shipment->paid_amount, 2),
                'unpaid_amount' => $shipment->unpaid_amount,
                'payment_due_date' => $shipment->payment_due_date?->toDateString(),
                'is_payment_overdue' => $shipment->is_payment_overdue,
            ];
        }

        if ($withItems) {
            $payload['items'] = $shipment->items
                ->map(fn (ShipmentItem $item) => [
                    'id' => $item->id,
                    'product' => [
                        // Товар мог быть удалён с сайта — тогда остаются снимки
                        // названия и бренда, сделанные при приёме документа.
                        'uuid' => $item->product?->external_id,
                        'code' => $item->product?->code,
                        'sku' => $item->product?->sku,
                        'barcode' => $item->product?->barcode,
                        'name' => $item->product?->name ?? $item->product_name_snapshot,
                        'brand' => $item->brand_name_snapshot,
                    ],
                    'order_uuid' => $item->order_uuid,
                    'quantity' => (int) $item->quantity,
                    'price' => round((float) $item->price, 2),
                    'auto_discount_percent' => round((float) $item->auto_discount_percent, 2),
                    'manual_discount_percent' => round((float) $item->manual_discount_percent, 2),
                    'subtotal' => round((float) $item->subtotal, 2),
                    'total' => round((float) $item->total, 2),
                    'vat_rate' => $item->vat_rate,
                ])->values()->all();
        }

        return $payload;
    }

    /**
     * Заказы, по которым собрана реализация: 1С может собрать документ из
     * нескольких заказов, и клиенту нужно сопоставление с его номерами.
     *
     * @return list<array<string, mixed>>
     */
    public function relatedOrders(Shipment $shipment): array
    {
        return $shipment->getRelatedOrders()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'uuid' => $order->uuid,
                ...$order->clientNumberPayload(),
                'type' => $order->type?->value,
                'status' => $order->status?->value,
                'status_label' => $order->status?->label(),
            ])->values()->all();
    }

    /**
     * Карточка legacy: строка с составом, заказы и — при финансах — график оплаты.
     *
     * @return array<string, mixed>
     */
    public function legacyCard(Shipment $shipment, bool $finance): array
    {
        $payload = $this->payload($shipment, $finance, withItems: true);
        $payload['orders'] = $this->relatedOrders($shipment);

        if ($finance) {
            $payload['payment_schedule'] = PaymentSchedulePresenter::forShipment($shipment);
        }

        return $payload;
    }

    /**
     * Строка списка API v1: форма legacy плюс продавец.
     *
     * @return array<string, mixed>
     */
    public function row(Shipment $shipment, bool $finance, bool $withItems): array
    {
        return $this->payload($shipment, $finance, $withItems) + [
            'seller' => $this->seller($shipment),
        ];
    }

    /**
     * Карточка API v1. Место под блок `delivery` зарезервировано (эпик доставки).
     *
     * @return array<string, mixed>
     */
    public function card(Shipment $shipment, bool $finance): array
    {
        return $this->legacyCard($shipment, $finance) + [
            'seller' => $this->seller($shipment),
        ];
    }

    /**
     * Строка списка кабинета. Поля поиска (match_*) добавляет контроллер.
     *
     * @return array<string, mixed>
     */
    public function cabinetRow(Shipment $shipment, ?Currency $currency, bool $finance): array
    {
        return [
            'id' => $shipment->id,
            'number' => $this->number($shipment),
            'tax_id' => $shipment->tax_id,
            'date' => $shipment->date?->format('Y-m-d'),
            'updated_at' => $shipment->updated_at?->format('d.m.Y H:i'),
            'status' => $shipment->status,
            'status_label' => $shipment->status_label,
            'currency_code' => $shipment->currency_code,
            'total_amount' => $shipment->total_amount,
            'total_converted' => $this->amounts->convert((float) $shipment->total_amount, $shipment->currency_code, $currency),
            // Оплата — денормализованные поля, их ведёт SettlementProjector.
            // Считать на лету нельзя: экспорт идёт cursor()-ом, а фильтр —
            // по колонке. Закрыты флагом: цифры долга не сверены с 1С.
            ...($finance ? [
                'payment_status' => $shipment->payment_status,
                'payment_status_label' => $shipment->payment_status_label,
                'paid_amount' => (float) $shipment->paid_amount,
                'unpaid_amount' => $shipment->unpaid_amount,
            ] : []),
            'items_count' => $shipment->items->count(),
            'company' => $shipment->company ? [
                'id' => $shipment->company->id,
                'name' => $shipment->company->name,
            ] : null,
        ];
    }

    /**
     * Карточка реализации в кабинете.
     *
     * @param  Collection<string, Order>  $ordersByUuid  связанные заказы по uuid — для номеров в строках
     * @return array<string, mixed>
     */
    public function cabinetCard(Shipment $shipment, ?Currency $currency, bool $finance, Collection $ordersByUuid): array
    {
        return [
            'id' => $shipment->id,
            'number' => $this->number($shipment),
            'tax_id' => $shipment->tax_id,
            'date' => $shipment->date?->format('Y-m-d'),
            // v15.16.0: счёт-фактура из 1С — нужна бухгалтерии клиента.
            // v15.16.1: клиенту показываем ПЕЧАТНЫЙ номер — он сверяет по бумаге,
            // а не по внутреннему номеру базы 1С
            'invoice_number' => $shipment->invoice_number_display ?: $shipment->invoice_number,
            'invoice_date' => $shipment->invoice_date?->format('d.m.Y'),
            'updated_at' => $shipment->updated_at?->format('d.m.Y H:i'),
            'status' => $shipment->status,
            'status_label' => $shipment->status_label,
            'currency_code' => $shipment->currency_code,
            'total_amount' => $shipment->total_amount,
            'total_converted' => $this->amounts->convert((float) $shipment->total_amount, $shipment->currency_code, $currency),
            // Оплата, разнесение и график закрыты флагом cabinet.finance_enabled:
            // остаток по документу систематически больше реального долга, пока
            // цифры не сверены с 1С — клиенту такое показывать нельзя.
            ...($finance ? [
                'payment_status' => $shipment->payment_status,
                'payment_status_label' => $shipment->payment_status_label,
                'paid_amount' => (float) $shipment->paid_amount,
                'unpaid_amount' => $shipment->unpaid_amount,
                // Список закрывших платежей снят вместе с расшифровкой (fin-11):
                // 1С её не присылает, а какой платёж закрыл документ, видно
                // в акте сверки.
                'payments' => [],
                'payment_schedule' => PaymentSchedulePresenter::forShipment(
                    $shipment,
                    fn (float $amount): float => $this->amounts->convert($amount, $shipment->currency_code, $currency),
                ),
            ] : []),
            'company' => $shipment->company ? [
                'id' => $shipment->company->id,
                'name' => $shipment->company->name,
                'legal_name' => $shipment->company->legal_name,
                'tax_id' => $shipment->company->tax_id,
            ] : null,
            // v15.8.0: продавец по накладной — наше юрлицо. Для клиента это самое
            // заметное место: именно по реализации он сверяет документ.
            'seller' => $this->seller($shipment),
            'items' => $shipment->items->map(function (ShipmentItem $item) use ($currency, $ordersByUuid) {
                $order = $item->order_uuid ? $ordersByUuid->get($item->order_uuid) : null;

                return [
                    'id' => $item->id,
                    'order_id' => $order?->id,
                    'order_number' => $order?->clientLabel(),
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'price_converted' => $this->amounts->convert((float) $item->price, null, $currency),
                    'auto_discount_percent' => $item->auto_discount_percent,
                    'manual_discount_percent' => $item->manual_discount_percent,
                    'total' => $item->total,
                    'total_converted' => $this->amounts->convert((float) $item->total, null, $currency),
                    'vat_rate' => $item->vat_rate,
                    'product' => $item->product ? [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'sku' => $item->product->sku,
                        'slug' => $item->product->slug,
                        'image_url' => $item->product->getFirstMediaUrl('main'),
                        'brand' => $item->product->brand ? ['name' => $item->product->brand->name] : null,
                    ] : null,
                ];
            }),
        ];
    }

    /**
     * Связанный заказ в карточке кабинета (блок «Заказы по этой реализации»).
     *
     * @return array<string, mixed>
     */
    public function cabinetRelatedOrder(Order $order, ?Currency $currency): array
    {
        $originalTotalAmount = (float) ($order->original_total_amount ?? 0);

        return [
            'id' => $order->id,
            ...$order->clientNumberPayload(),
            'uuid' => $order->uuid,
            'type' => $order->type?->value,
            'status' => $order->status?->value,
            'status_label' => $order->status?->label() ?? 'Неизвестно',
            'company' => $order->company ? ['id' => $order->company->id, 'name' => $order->company->name] : null,
            'items_count' => $order->items_count,
            'shipments_count' => $order->shipments_count,
            'total_amount' => $order->total_amount,
            'total_converted' => $this->amounts->convert((float) $order->total_amount, $order->currency_code, $currency),
            'original_total_amount' => $originalTotalAmount,
            'original_total_converted' => $this->amounts->convert($originalTotalAmount, $order->currency_code, $currency),
            'currency_code' => $order->currency_code,
            'erp_created_at' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
            'erp_updated_at' => ($order->erp_updated_at ?? $order->updated_at)?->format('d.m.Y H:i'),
        ];
    }

    public function number(Shipment $shipment): string
    {
        return $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id);
    }

    /**
     * Продавец по накладной — наше юрлицо, от имени которого проведена реализация.
     *
     * `null` в трёх случаях, и во всех блок не показывается: выключен флаг,
     * организация не пришла, либо это заглушка — у неё вместо названия лежит UUID.
     *
     * @return array<string, mixed>|null
     */
    public function seller(Shipment $shipment): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $shipment->organization;

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

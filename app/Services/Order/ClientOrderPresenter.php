<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Currency\CabinetAmountConverter;
use App\Support\Cabinet\CabinetFinance;

/**
 * Представление заказа для клиента: строка списка и карточка.
 *
 * Две формы — кабинет (даты в формате экрана, суммы с пересчётом в валюту
 * клиента) и API v1 (ISO-даты, суммы в валюте документа, чтобы сходились с 1С).
 * Набор данных один и тот же: наружу уходит ровно то, что клиент видит в
 * кабинете, — себестоимости и служебных полей здесь нет.
 */
class ClientOrderPresenter
{
    /** @var array<int, array<string, mixed>> Стадии исполнения, посчитанные пакетом для текущего списка. */
    private array $fulfilment = [];

    public function __construct(
        private readonly CabinetAmountConverter $amounts,
        private readonly \App\Services\Pickup\OrderFulfilmentResolver $fulfilmentResolver,
    ) {}

    /**
     * Посчитать стадии исполнения (pick-03) сразу для всего списка — тремя запросами, без N+1.
     * Вызывается перед отрисовкой строк; карточка одного заказа считается лениво.
     *
     * @param  iterable<Order>  $orders
     */
    public function primeFulfilment(iterable $orders): void
    {
        if (config('pickup.enabled')) {
            $this->fulfilment = $this->fulfilmentResolver->forOrders($orders) + $this->fulfilment;
        }
    }

    /**
     * Стадия исполнения глазами клиента: резерв → на складе → собирается → собран → выдан.
     * null при выключенном рубильнике `pickup.enabled` — фронт и агент живут по статусу 1С.
     *
     * @return array<string, mixed>|null
     */
    public function fulfilment(Order $order): ?array
    {
        if (! config('pickup.enabled')) {
            return null;
        }

        return $this->fulfilment[$order->id] ??= $this->fulfilmentResolver->forOrder($order);
    }

    /**
     * То же для карточки заказа — со складской историей (в списках она не считается).
     *
     * @return array<string, mixed>|null
     */
    public function fulfilmentCard(Order $order): ?array
    {
        $view = $this->fulfilment($order);

        return $view === null ? null : $view + ['events' => $this->fulfilmentResolver->timeline($order)];
    }

    /**
     * Связи для кабинетной карточки заказа.
     */
    public function loadCabinetCard(Order $order): void
    {
        $order->load([
            'company:id,name,legal_name,tax_id',
            // is_stub обязателен в выборке: без него seller() не отличит
            // заглушку и покажет клиенту UUID вместо названия продавца
            'organization:id,name,legal_name,tax_id,is_stub',
            'items.product:id,name,sku,slug',
            'items.product.brand:id,name',
            'items.product.media',
            'statusHistories.user',
            'changeLogs.user',
            // Реализации внутренних юрлиц («Реклама») клиенту не показываем — как и в списке.
            'shipments' => fn ($query) => $query->withoutInternalOrganizations(),
        ]);
    }

    /**
     * Связи для карточки API. Товар берётся без витринных скоупов: скрытый на
     * сайте товар всё равно заказан, и без этого его строка приехала бы без артикулов.
     */
    public function loadApiCard(Order $order): void
    {
        $order->load([
            'company:id,name,legal_name,tax_id',
            'organization:id,name,legal_name,tax_id,is_stub',
            'items.product' => fn ($q) => $q->withoutGlobalScopes()
                ->select('id', 'external_id', 'code', 'sku', 'slug', 'name', 'brand_id'),
            'items.product.brand:id,name',
            'items.product.media',
            'statusHistories.user',
            'changeLogs.user',
            'shipments' => fn ($query) => $query->withoutInternalOrganizations()->withCount('items'),
        ]);
    }

    /**
     * Строка списка кабинета. Поля поиска (match_*) и изменения состава
     * добавляет контроллер — они зависят от запроса, а не от заказа.
     *
     * @return array<string, mixed>
     */
    public function cabinetRow(Order $order, ?Currency $currency): array
    {
        $originalTotalAmount = (float) ($order->original_total_amount ?? 0);

        return [
            'id' => $order->id,
            // Только номер 1С; пока его нет — null и подсказка, временный ORD-… клиенту не показываем
            ...$order->clientNumberPayload(),
            'uuid' => $order->uuid,
            'status' => $order->status?->value,
            'status_label' => $this->statusLabel($order->status),
            // pick-03: стадия исполнения поверх статуса 1С (null, пока рубильник pickup.enabled выключен)
            'fulfilment' => $this->fulfilment($order),
            'type' => $order->type?->value,
            'delivery_method' => $order->delivery_method?->value ?? 'delivery',
            'delivery_method_label' => $order->delivery_method?->label() ?? 'Доставка',
            'total_amount' => $order->total_amount,
            'total_converted' => $this->amounts->convert((float) $order->total_amount, $order->currency_code, $currency),
            'original_total_amount' => $originalTotalAmount,
            'original_total_converted' => $this->amounts->convert($originalTotalAmount, $order->currency_code, $currency),
            'currency_code' => $order->currency_code,
            'erp_created_at' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
            'erp_updated_at' => ($order->erp_updated_at ?? $order->updated_at)?->format('d.m.Y H:i'),
            'is_synced_with_erp' => $order->erp_created_at !== null,
            'company' => $order->company ? [
                'id' => $order->company->id,
                'name' => $order->company->name,
            ] : null,
            'items_count' => $order->items_count,
            'shipments_count' => $order->shipments_count,
            // Документы одного оформления связаны общим checkout_uuid: чекаут
            // расщепляет корзину по типам и создаёт до пяти заказов.
            // Именно uuid, а не cart_id: корзина живёт долго и переиспользуется
            'cart_id' => $order->cart_id,
            'checkout_uuid' => $order->checkout_uuid,
            'placed_at' => $order->created_at?->format('d.m.Y H:i'),
        ];
    }

    /**
     * Карточка заказа в кабинете — после {@see loadCabinetCard()}.
     *
     * @return array<string, mixed>
     */
    public function cabinetCard(Order $order, User $user): array
    {
        $currency = $this->amounts->currencyOf($user);

        return [
            'id' => $order->id,
            ...$order->clientNumberPayload(),
            'uuid' => $order->uuid,
            'status' => $order->status?->value,
            'status_label' => $this->statusLabel($order->status),
            // pick-03: стадия исполнения поверх статуса 1С (null, пока рубильник pickup.enabled выключен)
            'fulfilment' => $this->fulfilmentCard($order),
            // v16.9.0 (res-04): кнопка «Отменить заказ» — за глобальным рубильником
            // и только пока 1С не начала сборку (или заказ в окне резерва)
            'can_cancel' => $this->canCancel($order),
            // v16.9.0 (res-07): плашка резерва с таймером и кнопкой «В отгрузку».
            // reserved_until — фактический срок из 1С (может быть урезан их пределом)
            'reserve' => (bool) $order->reserve,
            // v16.9.1: версия состава — фронт отправляет её при правке,
            // устаревшая отбивается до отправки в шину
            'items_version' => (int) ($order->items_version ?? 0),
            'reserved_until' => $order->reserve ? $order->reserved_until?->toIso8601String() : null,
            'reserved_until_formatted' => $order->reserve
                ? $order->reserved_until?->timezone(config('app.timezone'))->format('d.m.Y H:i')
                : null,
            'type' => $order->type?->value,
            'comment' => $order->comment,
            'manager_comment' => $order->manager_comment,
            'warehouse_comment' => $order->warehouse_comment,
            'total_amount' => $order->total_amount,
            'total_converted' => $this->amounts->convert((float) $order->total_amount, $order->currency_code, $currency),
            // v15.16.0: предоплата по заказу из расшифровки платежей 1С.
            // Накладную не гасит — по ней ещё нет реализации.
            // Закрыта флагом cabinet.finance_enabled вместе с остальными
            // денежными данными кабинета: остаток «к доплате» считается от неё.
            ...(CabinetFinance::enabledFor($user) ? [
                'prepaid_amount' => (float) $order->prepaid_amount,
                'prepaid_converted' => $this->amounts->convert((float) $order->prepaid_amount, $order->currency_code, $currency),
            ] : []),
            'currency_code' => $order->currency_code,
            'created_at_formatted' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
            'company' => $this->companyFull($order),
            // v15.8.0: продавец — наше юрлицо, на которое 1С провела заказ.
            // Заглушку клиенту не показываем: вместо названия там UUID.
            'seller' => $this->seller($order),
            'delivery_address' => $order->delivery_address,
            'delivery_method' => $order->delivery_method?->value ?? 'delivery',
            'delivery_method_label' => $order->delivery_method?->label() ?? 'Доставка',
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price,
                'base_price' => $item->base_price,
                'final_price' => $item->final_price,
                'discount_percent' => $item->discount_percent,
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
                // v15.16.0: строка, отменённая в 1С при недоборе. Показываем
                // её клиенту, но она не входит в total_amount заказа
                'cancelled' => (bool) $item->cancelled,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                    'slug' => $item->product->slug,
                    'image_url' => $item->product->getFirstMediaUrl('main'),
                    'brand' => $item->product->brand ? [
                        'name' => $item->product->brand->name,
                    ] : null,
                ] : null,
            ]),
            'shipments' => $order->shipments->map(fn (Shipment $shipment) => [
                'id' => $shipment->id,
                'number' => $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id),
                'uuid' => $shipment->uuid,
                'date' => $shipment->date?->format('Y-m-d'),
                'status' => $shipment->status,
                'status_label' => $shipment->status_label,
                'total_amount' => $shipment->total_amount,
                'total_converted' => $this->amounts->convert((float) $shipment->total_amount, $shipment->currency_code, $currency),
                'currency_code' => $shipment->currency_code,
                'items_count' => $shipment->items()->count(),
                'updated_at' => $shipment->updated_at?->format('d.m.Y H:i'),
            ]),
            'status_histories' => $order->statusHistories->map(fn ($history) => [
                'id' => $history->id,
                'old_status' => $history->old_status,
                'new_status' => $history->new_status,
                'old_status_label' => $history->old_status_label,
                'new_status_label' => $history->new_status_label,
                'user_name' => $history->user ? $history->user->name : 'Система',
                'comment' => $history->comment,
                'created_at' => $history->created_at->format('d.m.Y H:i'),
                'created_at_iso' => $history->created_at->toIso8601String(),
                'created_at_human' => $history->created_at->locale('ru')->diffForHumans(),
            ]),
            'change_logs' => $order->changeLogs->map(fn ($log) => [
                'id' => $log->id,
                'type' => $log->type,
                'summary' => $log->summary,
                'changes' => $log->changes,
                'source' => $log->source,
                'user_name' => $log->user?->name,
                'old_total' => $log->old_total,
                'new_total' => $log->new_total,
                'created_at' => $log->created_at->format('d.m.Y H:i'),
                'created_at_iso' => $log->created_at->toIso8601String(),
                'created_at_human' => $log->created_at->locale('ru')->diffForHumans(),
            ]),
        ];
    }

    /**
     * Строка списка API v1. Суммы — в валюте документа, даты — ISO 8601.
     *
     * @return array<string, mixed>
     */
    public function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'number' => $this->number($order),
            'erp_number' => $order->erp_number,
            'type' => $order->type?->value,
            'type_label' => $order->type?->label(),
            'status' => $order->status?->value,
            'status_label' => $this->statusLabel($order->status),
            // pick-03: стадия исполнения поверх статуса 1С (null, пока рубильник pickup.enabled выключен)
            'fulfilment' => $this->fulfilment($order),
            'delivery_method' => $order->delivery_method?->value ?? 'delivery',
            'delivery_method_label' => $order->delivery_method?->label() ?? 'Доставка',
            'currency_code' => $order->currency_code ?? 'RUB',
            'total_amount' => round((float) $order->total_amount, 2),
            // Сумма до скидок — по базовым ценам строк.
            'original_total_amount' => round((float) ($order->original_total_amount ?? $order->items->sum(fn ($i) => (float) $i->base_price * (float) $i->quantity)), 2),
            'items_count' => (int) ($order->items_count ?? $order->items->count()),
            'shipments_count' => (int) ($order->shipments_count ?? 0),
            'company' => $order->company ? [
                'id' => $order->company->id,
                'name' => $order->company->name,
                'inn' => $order->company->tax_id,
            ] : null,
            // Документы одного оформления (заказ, предзаказ, промо…) связаны checkout_uuid.
            'checkout_uuid' => $order->checkout_uuid,
            'reserve' => (bool) $order->reserve,
            'reserved_until' => $order->reserve ? $order->reserved_until?->toIso8601String() : null,
            'items_version' => (int) ($order->items_version ?? 0),
            'is_synced_with_erp' => $order->erp_created_at !== null,
            // created_at — дата документа (из 1С, если заказ уже проведён); placed_at — оформление на сайте.
            'created_at' => ($order->erp_created_at ?? $order->created_at)?->toIso8601String(),
            'placed_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'erp_updated_at' => $order->erp_updated_at?->toIso8601String(),
        ];
    }

    /**
     * Карточка заказа API v1 — после {@see loadApiCard()}.
     *
     * @return array<string, mixed>
     */
    public function card(Order $order, User $user): array
    {
        $payload = array_merge($this->row($order), [
            // Карточка — со складской историей (pick-05): начали сборку, собран, выдан.
            'fulfilment' => $this->fulfilmentCard($order),
            'comment' => $order->comment,
            'manager_comment' => $order->manager_comment,
            'warehouse_comment' => $order->warehouse_comment,
            'delivery_address' => $order->delivery_address,
            'company' => $this->companyFull($order, inn: true),
            'seller' => $this->seller($order),
            'can_cancel' => $this->canCancel($order),
        ]);

        if (CabinetFinance::enabledFor($user)) {
            $payload['prepaid_amount'] = round((float) $order->prepaid_amount, 2);
        }

        $payload['items'] = $order->items->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->name,
            'quantity' => (int) $item->quantity,
            'price' => round((float) $item->price, 2),
            'base_price' => round((float) $item->base_price, 2),
            'final_price' => round((float) ($item->final_price ?? $item->price), 2),
            'discount_percent' => round((float) $item->discount_percent, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            // Строка, отменённая в 1С при недоборе: клиент видит её, в total_amount она не входит.
            'cancelled' => (bool) $item->cancelled,
            'product' => $item->product ? [
                'id' => $item->product->id,
                'uuid' => $item->product->external_id,
                'code' => $item->product->code,
                'sku' => $item->product->sku,
                'slug' => $item->product->slug,
                'name' => $item->product->name,
                'brand' => $item->product->brand?->name,
                'image_url' => $item->product->getFirstMediaUrl('main') ?: null,
            ] : null,
        ])->values()->all();

        $payload['shipments'] = $order->shipments->map(fn (Shipment $shipment) => [
            'id' => $shipment->id,
            'uuid' => $shipment->uuid,
            'number' => $shipment->erp_number ?? $shipment->number ?? ('#'.$shipment->id),
            'date' => $shipment->date?->toDateString(),
            'status' => $shipment->status,
            'status_label' => $shipment->status_label,
            'currency_code' => $shipment->currency_code ?? 'RUB',
            'total_amount' => round((float) $shipment->total_amount, 2),
            'items_count' => (int) ($shipment->items_count ?? $shipment->items()->count()),
            'updated_at' => $shipment->updated_at?->toIso8601String(),
        ])->values()->all();

        $payload['status_history'] = $order->statusHistories->map(fn ($history) => [
            'id' => $history->id,
            'old_status' => $history->old_status,
            'new_status' => $history->new_status,
            'old_status_label' => $history->old_status_label,
            'new_status_label' => $history->new_status_label,
            'user_name' => $history->user ? $history->user->name : 'Система',
            'comment' => $history->comment,
            'created_at' => $history->created_at->toIso8601String(),
        ])->values()->all();

        $payload['change_logs'] = $order->changeLogs->map(fn ($log) => [
            'id' => $log->id,
            'type' => $log->type,
            'summary' => $log->summary,
            'changes' => $log->changes,
            'source' => $log->source,
            'user_name' => $log->user?->name,
            'old_total' => $log->old_total !== null ? round((float) $log->old_total, 2) : null,
            'new_total' => $log->new_total !== null ? round((float) $log->new_total, 2) : null,
            'created_at' => $log->created_at->toIso8601String(),
        ])->values()->all();

        return $payload;
    }

    /**
     * Подпись заказа для текстов и выгрузок: номер 1С либо «от даты (номер присваивается)».
     */
    public function number(Order $order): string
    {
        return $order->clientLabel();
    }

    public function statusLabel(?OrderStatus $status): string
    {
        return $status?->label() ?? 'Неизвестно';
    }

    /**
     * Продавец документа — наше юрлицо, на которое 1С провела заказ (v15.8.0).
     *
     * `null` в трёх случаях, и во всех блок не показывается: выключен флаг,
     * организация не пришла, либо это заглушка — у заглушки вместо названия
     * лежит UUID, показывать его клиенту нельзя.
     *
     * @return array<string, mixed>|null
     */
    public function seller(Order $order): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $order->organization;

        if (! $organization || $organization->is_stub) {
            return null;
        }

        return [
            'name' => $organization->name,
            'legal_name' => $organization->legal_name,
            'tax_id' => $organization->tax_id,
        ];
    }

    private function canCancel(Order $order): bool
    {
        return (bool) config('order_reserve.enabled') && $order->cancellableByClient();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function companyFull(Order $order, bool $inn = false): ?array
    {
        if (! $order->company) {
            return null;
        }

        return [
            'id' => $order->company->id,
            'name' => $order->company->name,
            'legal_name' => $order->company->legal_name,
            ($inn ? 'inn' : 'tax_id') => $order->company->tax_id,
        ];
    }
}

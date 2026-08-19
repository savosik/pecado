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
        $order->load(['items.product', 'user', 'company.bankAccounts', 'user.region', 'assignedWarehouse.organization']);

        // ⚠️ БЛОКЕР: заказ уценки нельзя публиковать, пока склад некондиции не получил
        // external_id от 1С — иначе warehouse_uuids уйдёт пустым и 1С не поймёт, откуда
        // отгружать. Не отправляем и пишем warning; заказ на сайте остаётся, менеджер
        // увидит его в кабинете склада. Снять гейт — как только UUID прописан.
        $orderType = $order->type?->value ?? $order->type;

        if ($orderType === 'defect' && $this->defectWarehouseUuids() === []) {
            \Illuminate\Support\Facades\Log::warning(
                'Заказ уценки не опубликован в 1С: у склада некондиции нет external_id',
                ['order_uuid' => $order->uuid, 'order_number' => $order->number]
            );

            return;
        }

        // Тот же гейт для рекламных образцов: пока склад «Москва подарки» не заведён
        // и не получил external_id от 1С, публиковать образцы нельзя — пустой
        // warehouse_uuids хуже отсутствия сообщения
        if ($orderType === 'promo_sample' && $this->promoSampleWarehouseUuids() === []) {
            \Illuminate\Support\Facades\Log::warning(
                'Заказ рекламных образцов не опубликован в 1С: у склада «Москва подарки» нет external_id',
                ['order_uuid' => $order->uuid, 'order_number' => $order->number]
            );

            return;
        }

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

        // v16.5.0: organization_uuid — независимый переходный флаг от стопки
        // складов (1С может подтвердить одно раньше другого). Выключен по
        // умолчанию: ключ в payload вообще не появляется, легаси-приёмник
        // его не видит.
        if ((bool) config('erp.organization_uuid_publishing.enabled')) {
            $payload['organization_uuid'] = $this->resolveOrganizationUuid($order);
        }

        // v16.2.0: машинная связь заказа-замены с исходным заказом недобора.
        // Ключ добавляется только у заказов-замен и только под флагом — до
        // подтверждения 1С, что незнакомое поле не ломает приёмник, обычные
        // payload-ы не меняются байт-в-байт.
        if ((bool) config('substitutions.protocol_field_enabled')
            && $order->replacement_for_order_id !== null
            && $order->replacementFor?->uuid) {
            $payload['replaces_order_uuid'] = $order->replacementFor->uuid;
        }

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
        $payload['items'] = $order->items->values()->map(function ($item, $index) {
            $line = [
                // v15.16.0: номер строки уезжает вместе с позицией — 1С заводит
                // документ с теми же номерами, и roundtrip не теряет привязку
                // строк. Фолбэк на порядковый номер нужен для заказов, созданных
                // до появления колонки.
                'line_number' => $item->line_number ?? $index + 1,
                'product_uuid' => $item->product?->external_id,
                'quantity' => $item->quantity,
                'base_price' => (float) ($item->base_price ?? $item->price),
                'discount_percent' => (float) ($item->discount_percent ?? 0),
                // 1С берёт final_price авторитетно и не пересчитывает сумму через
                // discount_percent (подтверждено заказчиком). Для промо-позиции
                // это принципиально: при цене 0,01 ₽ пересчёт дал бы расхождение
                'final_price' => (float) ($item->final_price ?? $item->price),
            ];

            // Признак промо ставим только там, где он есть, — обычные позиции
            // payload не раздувают
            if ($item->promo_kind !== null) {
                $line['is_promo'] = true;
                $line['promo_kind'] = $item->promo_kind;
            }

            return $line;
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
        $type = $order->type?->value ?? $order->type ?? 'order';

        // Режим стопки складов (v16.5.0): склад зафиксирован сайтом при
        // оформлении — уходит ровно один UUID, 1С проводит строго по нему.
        // Затрагивает только type=order; ветки preorder/defect/promo_sample
        // ниже не меняются. Если у склада нет external_id — конфигурация
        // неполная: пишем warning и падаем в прежнее перечисление складов
        // региона, чтобы заказ не застрял на сайте.
        //
        // Переходный флаг легаси-совместимости: пока 1С не подтвердила новую
        // семантику, фиксация в исходящих сообщениях выключена — уходит
        // прежнее перечисление складов региона, и легаси-приёмник работает
        // как раньше. assigned_warehouse_id при этом сохранён в БД.
        if ($order->assigned_warehouse_id !== null
            && $type === 'order'
            && (bool) config('erp.stack_warehouse_pinning.enabled')) {
            $uuid = $order->assignedWarehouse?->external_id;

            if ($uuid) {
                return [$uuid];
            }

            \Illuminate\Support\Facades\Log::warning(
                'Стопка складов: у назначенного склада нет external_id, фолбэк на склады региона',
                [
                    'order_uuid' => $order->uuid,
                    'assigned_warehouse_id' => $order->assigned_warehouse_id,
                ]
            );
        }

        // Уценка отгружается со склада некондиции — он один и в регионы не входит,
        // поэтому регион здесь не участвует (в отличие от order/preorder).
        if ($type === 'defect') {
            return $this->defectWarehouseUuids();
        }

        // Рекламные образцы отгружаются со своего склада, регион здесь не участвует
        if ($type === 'promo_sample') {
            return $this->promoSampleWarehouseUuids();
        }

        // Подотчётные промо-позиции лежат на обычных складах наличия региона,
        // поэтому ниже они идут по той же ветке, что и `order`

        $region = $order->user?->region;

        if (! $region) {
            return [];
        }

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
     * Наша организация для заказа — только если определена однозначно.
     *
     * Для стопочного заказа (`assigned_warehouse_id` задан, `type=order`) —
     * организация конкретного зафиксированного склада; известна сайту всегда,
     * независимо от флага `stack_warehouse_pinning` (тот гейтит только состав
     * `warehouse_uuids`, а не эту привязку).
     *
     * Для остальных случаев (в т.ч. стопка не сработала или не включена) —
     * организация всех складов, с которых физически может уйти заказ этого
     * типа: если она везде одна и та же, отдаём её; при нескольких разных
     * организациях или отсутствии привязки — null (1С выбирает как раньше).
     */
    private function resolveOrganizationUuid(\App\Models\Order $order): ?string
    {
        $type = $order->type?->value ?? $order->type ?? 'order';

        if ($order->assigned_warehouse_id !== null && $type === 'order') {
            return $order->assignedWarehouse?->organization?->external_id;
        }

        $warehouses = match ($type) {
            'defect' => \App\Models\Warehouse::query()->where('is_defect', true)->get(),
            'promo_sample' => \App\Models\Warehouse::query()->promoSample()->get(),
            'preorder' => $order->user?->region?->preorderWarehouses()->get() ?? collect(),
            default => $order->user?->region?->primaryWarehouses()->get() ?? collect(),
        };

        $organizationIds = $warehouses->pluck('organization_id')->filter()->unique();

        return $organizationIds->count() === 1
            ? \App\Models\Organization::find($organizationIds->first())?->external_id
            : null;
    }

    /**
     * UUID склада(ов) некондиции — источник отгрузки заказов уценки.
     *
     * @return string[]
     */
    private function defectWarehouseUuids(): array
    {
        return \App\Models\Warehouse::query()
            ->where('is_defect', true)
            ->pluck('external_id')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * UUID склада рекламных образцов («Москва подарки»).
     *
     * Пока склад не заведён или не получил external_id от 1С, метод возвращает
     * пустой массив, и гейт выше не даёт опубликовать заказ. Это корректное
     * поведение, а не ошибка.
     *
     * @return string[]
     */
    private function promoSampleWarehouseUuids(): array
    {
        return \App\Models\Warehouse::query()
            ->promoSample()
            ->pluck('external_id')
            ->filter()
            ->values()
            ->toArray();
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

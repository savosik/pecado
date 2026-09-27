<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\Warehouse;

/**
 * Склады отгрузки заказа — те же UUID, что уходят в 1С в `warehouse_uuids`
 * сообщения order.created. На заказе они не хранятся, а выводятся из типа
 * заказа и региона клиента, поэтому единственный источник — этот резолвер:
 * им пользуются и публикация в шину, и проверка группы совместной отгрузки
 * (v16.11.0: в группе ровно один склад).
 */
class OrderWarehouseResolver
{
    /**
     * Для 'order' — основные склады региона, для 'preorder' — склады предзаказа,
     * для 'defect' — склады некондиции, для 'promo_sample' — склад образцов.
     * Без региона — пусто.
     *
     * @return string[]
     */
    public function resolve(Order $order): array
    {
        $type = $order->type?->value ?? $order->type ?? 'order';

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
     * UUID склада(ов) некондиции — источник отгрузки заказов уценки.
     *
     * @return string[]
     */
    public function defectWarehouseUuids(): array
    {
        return Warehouse::query()
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
     * пустой массив, и гейт публикации не даёт отправить заказ. Это корректное
     * поведение, а не ошибка.
     *
     * @return string[]
     */
    public function promoSampleWarehouseUuids(): array
    {
        return Warehouse::query()
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

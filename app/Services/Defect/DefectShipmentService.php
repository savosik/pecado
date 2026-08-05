<?php

namespace App\Services\Defect;

use App\Enums\DefectClosedReason;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\ProductDefect;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

/**
 * Списание партий некондиции по реализациям из 1С.
 *
 * Важно: физический остаток партии (product_defects.quantity) реализация НЕ
 * уменьшает. Доступность на витрине уже считается как quantity − резерв
 * (DefectStockService), где резерв — это позиции живых заказов уценки. Отдельно
 * вычитать отгруженное означало бы двойной учёт.
 *
 * Роль реализации — зафиксировать, что партия израсходована физически, и
 * закрыть её (sold_out), чтобы она ушла из учёта, даже если заказ-резерв позже
 * будет удалён. Расчёт идемпотентный: отгруженное пересчитывается из текущего
 * состояния (не инкрементом), поэтому повторная доставка того же документа или
 * его отмена приводят партию в корректное состояние.
 *
 * Отмена в 1С возвращает партию в продажу — по любому из путей: реализация
 * удалена (`shipment.deleted`), переведена в статус `cancelled`
 * (`shipment.updated`) или снят сам заказ уценки (`order.deleted`). Партию,
 * списанную кладовщиком вручную (written_off), автоматика не трогает.
 *
 * Ограничение: если у одного товара в заказе несколько партий (редкий случай —
 * разные дефекты одного артикула куплены одним заказом), отгруженное по товару
 * не разводится между ними точно. На закрытие это влияет консервативно: партия
 * закроется, только когда отгруженного хватает на её объём.
 */
class DefectShipmentService
{
    /** Статус реализации в 1С, при котором отгрузки по документу нет. */
    private const SHIPMENT_CANCELLED = 'cancelled';

    /**
     * Пересчитать состояние партий, затронутых реализацией.
     */
    public function reconcileForShipment(Shipment $shipment): void
    {
        $orderUuids = $shipment->items()
            ->whereNotNull('order_uuid')
            ->pluck('order_uuid')
            ->unique()
            ->all();

        if ($orderUuids === []) {
            return;
        }

        // Партии, на которые ссылаются позиции этих заказов уценки.
        //
        // Удалённые заказы намеренно не отфильтрованы: в 1С отменяют разом и
        // заказ, и реализацию, и если заказ уже помечен удалённым, партия иначе
        // осталась бы «Распродана» навсегда — пересчитывать было бы нечего.
        $defectIds = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.uuid', $orderUuids)
            ->where('orders.type', OrderType::DEFECT->value)
            ->whereNotNull('order_items.product_defect_id')
            ->pluck('order_items.product_defect_id')
            ->unique()
            ->all();

        foreach ($defectIds as $defectId) {
            $this->reconcileDefect((int) $defectId);
        }
    }

    /**
     * Пересчитать состояние партий, привязанных к заказу уценки.
     *
     * Нужен там, где меняется сам заказ, а не реализация: удаление заказа в 1С
     * снимает резерв и обнуляет отгруженное по нему, поэтому закрытая партия
     * должна вернуться в продажу — события по реализации при этом может и не быть.
     */
    public function reconcileForOrder(Order $order): void
    {
        if ($order->type !== OrderType::DEFECT) {
            return;
        }

        $defectIds = DB::table('order_items')
            ->where('order_id', $order->id)
            ->whereNotNull('product_defect_id')
            ->pluck('product_defect_id')
            ->unique()
            ->all();

        foreach ($defectIds as $defectId) {
            $this->reconcileDefect((int) $defectId);
        }
    }

    /**
     * Пересчитать все партии, закрытые как распроданные.
     *
     * Разовая починка данных: события отмены, пришедшие до v15.9.2, партии не
     * открывали, и на складе остались «вечно распроданные» партии. Живому обмену
     * команда не нужна — там пересчёт идёт по событию.
     *
     * @return array<int, int> id партий, вернувшихся в продажу
     */
    public function reconcileClosedBatches(): array
    {
        $reopened = [];

        ProductDefect::query()
            ->where('closed_reason', DefectClosedReason::SOLD_OUT->value)
            ->select(['id'])
            ->chunkById(200, function ($defects) use (&$reopened) {
                foreach ($defects as $defect) {
                    $this->reconcileDefect((int) $defect->id);

                    if (! ProductDefect::whereKey($defect->id)->whereNotNull('closed_at')->exists()) {
                        $reopened[] = (int) $defect->id;
                    }
                }
            });

        return $reopened;
    }

    /**
     * Привести одну партию в соответствие с фактически отгруженным.
     */
    private function reconcileDefect(int $defectId): void
    {
        $defect = ProductDefect::find($defectId);

        if (! $defect) {
            return;
        }

        // Партию, списанную вручную кладовщиком, реализация не трогает.
        if ($defect->closed_reason === DefectClosedReason::WRITTEN_OFF) {
            return;
        }

        $shipped = $this->shippedQuantity($defectId, (int) $defect->product_id);
        $fullyShipped = $shipped >= (int) $defect->quantity;

        if ($fullyShipped && ! $defect->isClosed()) {
            $defect->close(DefectClosedReason::SOLD_OUT);

            return;
        }

        // Откат реализации (shipment.deleted / уменьшение количества): если партия
        // была закрыта как распроданная, а отгруженного больше не хватает — открываем.
        if (! $fullyShipped && $defect->closed_reason === DefectClosedReason::SOLD_OUT) {
            $defect->reopen();
        }
    }

    /**
     * Сколько единиц партии фактически отгружено — по неотменённым реализациям.
     *
     * Привязка отгруженного к партии: позиция реализации (order_uuid + product_id)
     * сопоставляется с позицией заказа уценки, ссылающейся на эту партию.
     *
     * Отменённой считается реализация, которую 1С либо удалила (`shipment.deleted`
     * → soft-delete), либо перевела в статус `cancelled` через `shipment.updated`.
     * Второй путь встречается чаще: документ в 1С остаётся, отгрузки по нему нет.
     * Заказ уценки, помеченный удалённым, тоже обнуляет отгруженное — товар
     * вернулся на склад.
     */
    private function shippedQuantity(int $defectId, int $productId): int
    {
        return (int) DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->join('orders', function ($join) {
                $join->on('orders.uuid', '=', 'shipment_items.order_uuid')
                    ->where('orders.type', '=', OrderType::DEFECT->value)
                    ->whereNull('orders.deleted_at');
            })
            ->join('order_items', function ($join) use ($defectId) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->on('order_items.product_id', '=', 'shipment_items.product_id')
                    ->where('order_items.product_defect_id', '=', $defectId);
            })
            ->whereNull('shipments.deleted_at')
            ->where('shipments.status', '!=', self::SHIPMENT_CANCELLED)
            ->where('shipment_items.product_id', $productId)
            ->sum('shipment_items.quantity');
    }
}

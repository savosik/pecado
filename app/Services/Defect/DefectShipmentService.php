<?php

namespace App\Services\Defect;

use App\Enums\DefectClosedReason;
use App\Enums\OrderType;
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
 * Ограничение: если у одного товара в заказе несколько партий (редкий случай —
 * разные дефекты одного артикула куплены одним заказом), отгруженное по товару
 * не разводится между ними точно. На закрытие это влияет консервативно: партия
 * закроется, только когда отгруженного хватает на её объём.
 */
class DefectShipmentService
{
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
        $defectIds = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.uuid', $orderUuids)
            ->where('orders.type', OrderType::DEFECT->value)
            ->whereNull('orders.deleted_at')
            ->whereNotNull('order_items.product_defect_id')
            ->pluck('order_items.product_defect_id')
            ->unique()
            ->all();

        foreach ($defectIds as $defectId) {
            $this->reconcileDefect((int) $defectId);
        }
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
            $defect->forceFill(['closed_at' => null, 'closed_reason' => null])->save();
        }
    }

    /**
     * Сколько единиц партии фактически отгружено — по неотменённым реализациям.
     *
     * Привязка отгруженного к партии: позиция реализации (order_uuid + product_id)
     * сопоставляется с позицией заказа уценки, ссылающейся на эту партию.
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
            ->where('shipment_items.product_id', $productId)
            ->sum('shipment_items.quantity');
    }
}

<?php

namespace App\Services\Erp\Support;

use App\Models\Order;

/**
 * Сохранение складских привязок позиций уценки при пересборке заказа из 1С.
 *
 * product_defect_id и defect_description — внутренние поля сайта (какую именно
 * партию брака отдать со склада). 1С про них не знает и в payload не присылает.
 * Обработчики order.created/order.updated при upsert делают полную замену
 * позиций (items()->delete() + create), из-за чего эти поля обнулялись, и
 * заказ уценки терял привязку к партии → пустая карточка на «К отгрузке».
 *
 * Трейт снимает поля со старых позиций до их удаления и переносит на новые,
 * сопоставляя по product_id. Для одного товара с несколькими строками (разные
 * партии) используется FIFO-очередь.
 */
trait PreservesDefectItemLinks
{
    /**
     * Снимок складских привязок текущих позиций заказа (до их удаления).
     *
     * @return array<int, list<array{product_defect_id: int|null, defect_description: string|null}>>
     */
    protected function captureDefectLinks(Order $order): array
    {
        $map = [];

        $items = $order->items()
            ->whereNotNull('product_id')
            ->whereNotNull('product_defect_id')
            ->get(['product_id', 'product_defect_id', 'defect_description']);

        foreach ($items as $item) {
            $map[(int) $item->product_id][] = [
                'product_defect_id' => $item->product_defect_id,
                'defect_description' => $item->defect_description,
            ];
        }

        return $map;
    }

    /**
     * Достаёт (FIFO) складскую привязку для товара из снимка. Если привязки
     * нет — возвращает пустые поля (обычный заказ либо новая позиция).
     *
     * @param  array<int, list<array{product_defect_id: int|null, defect_description: string|null}>>  $map
     * @return array{product_defect_id: int|null, defect_description: string|null}
     */
    protected function pullDefectLink(array &$map, ?int $productId): array
    {
        if ($productId !== null && ! empty($map[$productId])) {
            return array_shift($map[$productId]);
        }

        return ['product_defect_id' => null, 'defect_description' => null];
    }
}

<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Страховой буфер остатков (эпик buf-00): чтение эффективного размера буфера.
 *
 * Буфер — производная, не поле остатка (прецеденты PromoStockService и
 * DefectStockService): `product_warehouse.quantity` остаётся абсолютным
 * значением из 1С, вычитание происходит при показе. Здесь только чтение
 * карты; занижение применяют потребители (buf-04/buf-05) и только для
 * клиентов с галочкой `users.stock_buffer_enabled`.
 *
 * Рисковых SKU ~100–150, поэтому для больших списков (выгрузки, каталог
 * целиком) дешевле поднять все буферы одним запросом без WHERE IN и
 * пересечь в PHP, чем гнать в MySQL список из тысяч id.
 */
class StockBufferService
{
    /**
     * Порог, после которого WHERE IN по списку товаров дороже полного чтения
     * таблицы буферов (в ней сотни строк, не тысячи).
     */
    private const FULL_SCAN_THRESHOLD = 500;

    /**
     * Эффективный буфер одного товара, шт.
     */
    public function buffer(int $productId): int
    {
        return $this->bufferMap([$productId])[$productId];
    }

    /**
     * Батч-карта эффективных буферов: товар → на сколько штук занижать показ.
     *
     * Один запрос независимо от размера списка. Ручная пометка склада
     * (`manual_qty`) побеждает расчёт; отсутствие записи = 0.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, int>
     */
    public function bufferMap(iterable $productIds): array
    {
        $result = [];
        foreach ($productIds as $id) {
            $result[(int) $id] = 0;
        }

        if ($result === []) {
            return [];
        }

        $query = DB::table('product_stock_buffers')
            ->select(['product_id', 'buffer_qty', 'manual_qty']);

        if (count($result) <= self::FULL_SCAN_THRESHOLD) {
            $query->whereIn('product_id', array_keys($result));
        }

        foreach ($query->get() as $row) {
            $productId = (int) $row->product_id;

            if (! array_key_exists($productId, $result)) {
                continue;
            }

            $result[$productId] = max((int) ($row->manual_qty ?? $row->buffer_qty), 0);
        }

        return $result;
    }
}

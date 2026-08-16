<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Страховой буфер остатков (эпик buf-00): чтение эффективного размера буфера.
 *
 * Буфер — производная, не поле остатка (прецеденты PromoStockService и
 * DefectStockService): `product_warehouse.quantity` остаётся абсолютным
 * значением из 1С, вычитание происходит при показе (StockService, buf-04)
 * и только для клиентов с галочкой `users.stock_buffer_enabled`.
 *
 * Экземпляр скоуплен на запрос (AppServiceProvider): вся таблица буферов
 * (~100–150 строк) поднимается одним запросом при первом обращении, дальше
 * карточка + варианты + похожие + корзина отвечают из памяти — дисциплина
 * «один запрос к product_stock_buffers на HTTP-запрос».
 */
class StockBufferService
{
    /**
     * Эффективные буферы всех товаров: product_id → шт. NULL до первого чтения.
     *
     * @var array<int, int>|null
     */
    private ?array $effective = null;

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
     * Ручная пометка склада (`manual_qty`) побеждает расчёт; отсутствие
     * записи = 0.
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

        $effective = $this->allEffective();

        foreach ($result as $productId => $zero) {
            if (isset($effective[$productId])) {
                $result[$productId] = $effective[$productId];
            }
        }

        return $result;
    }

    /**
     * Полная карта эффективных буферов, поднятая один раз на запрос.
     *
     * @return array<int, int>
     */
    private function allEffective(): array
    {
        if ($this->effective !== null) {
            return $this->effective;
        }

        $this->effective = [];

        $rows = DB::table('product_stock_buffers')
            ->select(['product_id', 'buffer_qty', 'manual_qty'])
            ->get();

        foreach ($rows as $row) {
            $qty = max((int) ($row->manual_qty ?? $row->buffer_qty), 0);

            if ($qty > 0) {
                $this->effective[(int) $row->product_id] = $qty;
            }
        }

        return $this->effective;
    }
}

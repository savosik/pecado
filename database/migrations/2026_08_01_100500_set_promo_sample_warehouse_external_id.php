<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UUID склада «Москва реклама» из 1С — снимает гейт публикации заказов
 * рекламных образцов (`orders.type = promo_sample`).
 *
 * Пока у склада нет `external_id`, `PublishOrderToErp` не отправляет такие заказы
 * в шину: пустой `warehouse_uuids` для 1С хуже отсутствия сообщения. После этой
 * миграции гейт снимается сам.
 *
 * Три состояния окружения, все обрабатываются идемпотентно:
 *
 * 1. Склад уже приехал из 1С (нашёлся по `external_id`) — только ставим флаг.
 * 2. Склад заведён вручную по имени, без UUID — прописываем UUID и флаг.
 * 3. Склада нет вовсе — создаём. Без записи в `warehouses` остатки пробников
 *    по шине тоже не дойдут: `HandleStockUpdated` ищет склад по `external_id`
 *    и сам его **не создаёт**.
 *
 * ⚠️ Склад рекламных образцов не должен входить ни в один регион — иначе его
 * остатки попадут в витринное наличие и пробники начнут продаваться как обычный
 * товар. Поэтому привязки к регионам, если они откуда-то появились, снимаются.
 * Это и есть главный инвариант карточки promo-11.
 */
return new class extends Migration
{
    private const PROMO_SAMPLE_WAREHOUSE_UUID = '9da1768a-40d4-11e1-a692-001e6711ed1d';

    private const PROMO_SAMPLE_WAREHOUSE_NAME = 'Москва реклама';

    public function up(): void
    {
        $warehouseId = $this->resolveWarehouseId();

        DB::table('warehouses')
            ->where('id', $warehouseId)
            ->update([
                'external_id' => self::PROMO_SAMPLE_WAREHOUSE_UUID,
                'is_promo_sample' => true,
                // Взаимоисключающесть типов: рекламный склад не может быть складом некондиции
                'is_defect' => false,
                'updated_at' => now(),
            ]);

        // Пробники не продаются на витрине — склад вне регионов
        DB::table('region_warehouse')->where('warehouse_id', $warehouseId)->delete();
    }

    /**
     * Найти склад по UUID, затем по имени; если нет — создать.
     */
    private function resolveWarehouseId(): int
    {
        $byUuid = DB::table('warehouses')
            ->where('external_id', self::PROMO_SAMPLE_WAREHOUSE_UUID)
            ->value('id');

        if ($byUuid !== null) {
            return (int) $byUuid;
        }

        $byName = DB::table('warehouses')
            ->where('name', self::PROMO_SAMPLE_WAREHOUSE_NAME)
            ->whereNull('external_id')
            ->value('id');

        if ($byName !== null) {
            return (int) $byName;
        }

        return (int) DB::table('warehouses')->insertGetId([
            'name' => self::PROMO_SAMPLE_WAREHOUSE_NAME,
            'external_id' => self::PROMO_SAMPLE_WAREHOUSE_UUID,
            'is_promo_sample' => true,
            'is_defect' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Откат снимает только UUID и флаг — склад и его остатки не трогаем.
     * Привязки к регионам не восстанавливаются: по правилам волны 3 их
     * у этого склада быть не должно.
     */
    public function down(): void
    {
        DB::table('warehouses')
            ->where('external_id', self::PROMO_SAMPLE_WAREHOUSE_UUID)
            ->update([
                'external_id' => null,
                'is_promo_sample' => false,
                'updated_at' => now(),
            ]);
    }
};

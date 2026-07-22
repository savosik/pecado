<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UUID склада «Москва некондиция» из 1С.
 *
 * Склад заведён вручную на prod до появления фичи уценки и не имел external_id —
 * из-за чего заказы уценки гейтились в PublishOrderToErp (не публиковались в шину).
 * После прописи UUID гейт снимается сам: warehouse_uuids заполнится.
 *
 * Идемпотентно: проставляем только если external_id ещё пуст, и только складу
 * некондиции. На окружениях без этого склада (локальные, тесты) миграция —
 * no-op. UUID при занятости другим складом не трогаем (unique-констрейнт).
 */
return new class extends Migration
{
    private const DEFECT_WAREHOUSE_UUID = '32df2aeb-737e-11e8-8118-00155d00e605';

    public function up(): void
    {
        // Не перетираем, если UUID уже занят каким-либо складом.
        $alreadyUsed = DB::table('warehouses')
            ->where('external_id', self::DEFECT_WAREHOUSE_UUID)
            ->exists();

        if ($alreadyUsed) {
            return;
        }

        DB::table('warehouses')
            ->where('is_defect', true)
            ->whereNull('external_id')
            ->update(['external_id' => self::DEFECT_WAREHOUSE_UUID]);
    }

    public function down(): void
    {
        DB::table('warehouses')
            ->where('external_id', self::DEFECT_WAREHOUSE_UUID)
            ->update(['external_id' => null]);
    }
};

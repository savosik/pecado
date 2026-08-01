<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Склад рекламных образцов называется «Москва подарки», а не «Москва реклама».
 *
 * Название уточнено заказчиком 2026-08-01, уже после того, как склад был заведён
 * миграцией `2026_08_01_100500_set_promo_sample_warehouse_external_id`. UUID тот же —
 * `9da1768a-40d4-11e1-a692-001e6711ed1d`, обмен с 1С идентифицирует склад по нему,
 * поэтому переименование на контракт не влияет.
 *
 * Почему это важно, а не косметика: название попадает в лист отбора
 * (`PromoPickListFormatter::HEADING_SAMPLE`), который уезжает в `warehouse_comment`
 * заказа и печатается в 1С. Кладовщик ищет склад по названию из учётной системы —
 * расхождение отправит его не туда.
 *
 * 1С название складов не присылает (`HandleStockUpdated` ищет склад по `external_id`
 * и трогает только остатки), так что переименование не откатится следующей выгрузкой.
 */
return new class extends Migration
{
    private const PROMO_SAMPLE_WAREHOUSE_UUID = '9da1768a-40d4-11e1-a692-001e6711ed1d';

    private const OLD_NAME = 'Москва реклама';

    private const NEW_NAME = 'Москва подарки';

    public function up(): void
    {
        $this->rename(self::NEW_NAME, self::OLD_NAME);
    }

    public function down(): void
    {
        $this->rename(self::OLD_NAME, self::NEW_NAME);
    }

    /**
     * Переименовать склад: сначала по UUID (надёжно), затем по прежнему названию —
     * на случай, если склад заводили руками и UUID до него не дошёл.
     */
    private function rename(string $to, string $from): void
    {
        $renamed = DB::table('warehouses')
            ->where('external_id', self::PROMO_SAMPLE_WAREHOUSE_UUID)
            ->update(['name' => $to, 'updated_at' => now()]);

        if ($renamed === 0) {
            DB::table('warehouses')
                ->where('name', $from)
                ->update(['name' => $to, 'updated_at' => now()]);
        }
    }
};

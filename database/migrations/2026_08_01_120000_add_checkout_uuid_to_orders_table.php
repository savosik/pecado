<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Идентификатор оформления: одна покупка — один `checkout_uuid`.
 *
 * Документы одного оформления связывались по `cart_id`, но корзина живёт долго
 * и переиспользуется: после чекаута она очищается, а запись остаётся той же.
 * В кабинете это давало «Одно оформление · документов: 10» — в группу слипалась
 * вся история корзины за месяцы (у активных клиентов — сотни документов).
 *
 * Заполняет колонку `OrderAssembler`: одно значение на всю сборку, сколько бы
 * заказов она ни породила. У заказов, приехавших из 1С, значение остаётся NULL —
 * оформления на сайте у них не было и группировать нечего.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('checkout_uuid', 36)->nullable()->after('cart_id')
                ->comment('Идентификатор оформления: у всех документов одной покупки он общий; NULL — заказ создан в 1С');
            $table->index('checkout_uuid');
        });

        $this->backfill();
    }

    /**
     * История: заказы одной сборки создаются в одной транзакции, поэтому
     * `created_at` у них совпадает с точностью до секунды. Пара
     * «корзина + момент создания» и восстанавливает группы задним числом.
     *
     * Значения исторических групп детерминированы (хеш от пары), новые — uuid.
     * Формат не важен: идентификатор непрозрачный, сравнивается только на равенство.
     */
    private function backfill(): void
    {
        // Один запрос вместо тысяч: на проде заказов десятки тысяч
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "UPDATE orders SET checkout_uuid = MD5(CONCAT(cart_id, '|', created_at))
                 WHERE cart_id IS NOT NULL AND checkout_uuid IS NULL"
            );

            return;
        }

        // Прочие драйверы (в тестах — SQLite): группируем на стороне PHP
        $groups = [];

        DB::table('orders')
            ->whereNotNull('cart_id')
            ->whereNull('checkout_uuid')
            ->orderBy('id')
            ->select('id', 'cart_id', 'created_at')
            ->each(function ($order) use (&$groups) {
                $groups[$order->cart_id.'|'.$order->created_at][] = $order->id;
            });

        foreach ($groups as $ids) {
            DB::table('orders')->whereIn('id', $ids)->update(['checkout_uuid' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['checkout_uuid']);
            $table->dropColumn('checkout_uuid');
        });
    }
};

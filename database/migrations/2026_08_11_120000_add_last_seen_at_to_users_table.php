<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Последний визит партнёра на сайт — для CRM.
 *
 * Менеджеру нужно видеть, пользуется ли партнёр сайтом вообще: заказ мог
 * приехать из 1С, а в кабинет партнёр не заходил ни разу.
 *
 * Момент входа (login) для этого не годится: с «запомнить меня» партнёр может
 * не логиниться месяцами, заходя на сайт ежедневно, — карточка показывала бы
 * «был в марте» у самого активного клиента. Поэтому пишем именно активность:
 * middleware обновляет отметку не чаще раза в 15 минут (см. TrackUserLastSeen).
 *
 * Заполнение существующих строк. Истории визитов в проекте не было вообще:
 * на prod сессии живут в Redis и истекают за 120 минут, таблица `sessions`
 * там пуста. Без заполнения все 800+ партнёров получили бы метку «ни разу
 * не заходил» — то есть признак соврал бы ровно там, ради чего заводился.
 *
 * Поэтому берём следы, которые остаются в БД и возможны только с сайта:
 *   - заказ, оформленный в кабинете (`orders.checkout_uuid` не пуст — заказы
 *     из 1С приходят без него);
 *   - корзина партнёра (`carts.updated_at`);
 *   - живые сессии, если драйвер всё-таки database (dev).
 *
 * Это нижняя оценка: партнёр мог смотреть каталог и ничего не положить в
 * корзину. Зато «был 12.05» вместо «не заходил никогда» — ошибка в сторону,
 * которая не поднимет менеджера на ложный звонок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('email_verified_at')
                ->comment('Последний визит на сайт: обновляется при активности авторизованного пользователя не чаще раза в 15 минут. NULL — ни разу не заходил');

            // Отбор «давно не заходил» и сортировка по визиту в списке партнёров CRM.
            $table->index('last_seen_at', 'users_last_seen_at_index');
        });

        $this->backfill();
    }

    /**
     * Восстановить отметку по следам, которые партнёр мог оставить только с сайта.
     *
     * Берём максимум по каждому источнику: любой из них — свидетельство визита,
     * и позже произошедшее вытесняет более раннее.
     */
    private function backfill(): void
    {
        $seen = [];

        $remember = function (array $pairs) use (&$seen): void {
            foreach ($pairs as $userId => $at) {
                $userId = (int) $userId;

                if ($at === null) {
                    continue;
                }

                if (! isset($seen[$userId]) || $at > $seen[$userId]) {
                    $seen[$userId] = $at;
                }
            }
        };

        // Заказ из кабинета: checkout_uuid проставляет оформление на сайте,
        // у заказов, приехавших из 1С, он пуст.
        $remember(DB::table('orders')
            ->whereNotNull('user_id')
            ->whereNotNull('checkout_uuid')
            ->groupBy('user_id')
            ->pluck(DB::raw('MAX(created_at)'), 'user_id')
            ->all());

        if (Schema::hasTable('carts')) {
            $remember(DB::table('carts')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->pluck(DB::raw('MAX(updated_at)'), 'user_id')
                ->all());
        }

        // Драйвер database — только на dev; last_activity там unix-время.
        if (Schema::hasTable('sessions')) {
            $sessions = DB::table('sessions')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->pluck(DB::raw('MAX(last_activity)'), 'user_id')
                ->all();

            $remember(array_map(
                fn ($timestamp) => date('Y-m-d H:i:s', (int) $timestamp),
                $sessions,
            ));
        }

        foreach (array_chunk($seen, 500, true) as $chunk) {
            foreach ($chunk as $userId => $at) {
                DB::table('users')->where('id', $userId)->update(['last_seen_at' => $at]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_last_seen_at_index');
            $table->dropColumn('last_seen_at');
        });
    }
};

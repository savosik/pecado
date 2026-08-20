<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Снос контура подборок замен: сайт больше не предлагает клиенту замену
 * отменённой позиции.
 *
 * Причина — природа недобора оказалась двойной: строку снимает и склад при
 * закрытии расходного ордера, и сам клиент, попросив менеджера убрать позицию.
 * Ни в одном из случаев автоматическая подборка замен не уместна, поэтому
 * раздел «Недоборы» становится журналом отмен, а офферы, кандидаты, письма
 * клиенту и справочник взаимозаменяемости удаляются целиком.
 *
 * Даты отмен перенесены в `order_items.cancelled_at` предыдущей миграцией —
 * порядок важен: после дропа таблиц восстанавливать их неоткуда.
 *
 * Подписки клиентов на изменения заказов НЕ трогаем: удаляется только событие
 * «Подобрана замена по недобору», сами подписки и остальные события живут.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropReplacementLinks();
        $this->cleanSubscriptions();
        $this->dropEmailTemplate();

        Schema::dropIfExists('substitution_events');
        Schema::dropIfExists('product_substitutions');
        Schema::dropIfExists('substitution_offer_items');
        Schema::dropIfExists('substitution_offers');
    }

    public function down(): void
    {
        // Необратимо: таблицы подборок и связи заказов-замен восстановлению
        // не подлежат — данные удалены вместе с контуром.
    }

    /**
     * Связь «заказ-замена → исходный заказ»: заказов-замен больше не бывает.
     */
    private function dropReplacementLinks(): void
    {
        if (Schema::hasColumn('order_items', 'replaces_order_item_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('replaces_order_item_id');
            });
        }

        if (Schema::hasColumn('orders', 'replacement_for_order_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('replacement_for_order_id');
            });
        }
    }

    /**
     * Событие `substitution_offered` из подписок раздела «Заказы».
     */
    private function cleanSubscriptions(): void
    {
        if (! Schema::hasTable('entity_subscriptions')) {
            return;
        }

        DB::table('entity_subscriptions')
            ->where('section', 'orders')
            ->orderBy('id')
            ->select('id', 'events')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $events = json_decode((string) $row->events, true);

                    if (! is_array($events) || ! in_array('substitution_offered', $events, true)) {
                        continue;
                    }

                    $events = array_values(array_diff($events, ['substitution_offered']));

                    DB::table('entity_subscriptions')
                        ->where('id', $row->id)
                        ->update(['events' => json_encode($events)]);
                }
            });
    }

    /**
     * Шаблон письма-извинения: писем-подборок больше не бывает.
     */
    private function dropEmailTemplate(): void
    {
        if (! Schema::hasTable('crm_email_templates')) {
            return;
        }

        DB::table('crm_email_templates')
            ->where('name', 'Недобор: подборка замен')
            ->delete();
    }
};

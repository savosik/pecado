<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Снапшот привязки позиции заказа к акции.
 *
 * Промо-позиции уезжают отдельными заказами (`type = promo` / `promo_sample`),
 * и по позиции нужно понимать, какое правило её выдало и какого она вида:
 * подотчётная выписывается клиенту в накладную, рекламный образец — нет.
 *
 * Именно снапшот, а не вычисление на лету: правило акции могут отредактировать
 * или удалить, а заказ обязан остаться таким, каким его оформил клиент. Поэтому
 * FK на правило — `nullOnDelete`: удаление акции не уносит с собой заказы,
 * но `promo_kind` остаётся и документ по-прежнему читается.
 *
 * Ручная выдача: менеджер может добавить промо-позицию прямо в 1С. Такая позиция
 * приедет на сайт без `promotion_rule_id` — это норма, а не потеря данных.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('promotion_rule_id')->nullable()->after('defect_description')
                ->comment('Правило акции, выдавшее позицию (promotion_rules.id); NULL у обычных позиций и у промо-позиций, добавленных вручную в 1С')
                ->constrained('promotion_rules')->nullOnDelete();
            $table->string('promo_kind', 32)->nullable()->after('promotion_rule_id')
                ->comment("Вид промо-позиции: 'accountable' — подотчётная (выписывается в накладную), 'sample' — рекламный образец (не выписывается); NULL у обычных позиций");
        });

        // Перечень допустимых значений в комментарии столбца — по правилу
        // .claude/rules/db-comments.md ИИ-агент читает назначение поля из схемы
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY `type` VARCHAR(255) NOT NULL DEFAULT 'order' COMMENT ".
                "\"Тип заказа: 'order' — со склада, 'preorder' — предзаказ, 'defect' — уценка, 'promo' — подотчётные промо-позиции, 'promo_sample' — рекламные образцы\"");
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_rule_id');
            $table->dropColumn('promo_kind');
        });
    }
};

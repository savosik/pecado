<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фокус-перечень и снимок его состава на период (эпик mot-00, карточка mot-20).
 *
 * Признака фокусности в данных нет вовсе: ни колонки, ни тега, ни подходящего флага
 * у бренда (brands.is_featured — витринный). Поэтому перечень ведётся правилами:
 * одно правило brand → Pecado покрывает 277 товаров.
 *
 * Снимок нужен ради воспроизводимости: правила меняются приказом, а расчёт прошлого
 * месяца обязан оставаться прежним. Без снимка правка перечня задним числом молча
 * переписывала бы уже выплаченные премии.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_focus_rules', function (Blueprint $table) {
            $table->comment('Правила включения позиций в Фокус-перечень: бренд, категория или отдельный товар с периодом действия');
            $table->id()->comment('Первичный ключ');
            $table->string('scope', 20)->comment("Что включаем: 'brand' — весь бренд, 'category' — категория, 'product' — отдельный товар");
            $table->unsignedBigInteger('target_id')->comment('Идентификатор бренда, категории или товара — в зависимости от scope');
            $table->decimal('rate', 8, 5)->nullable()->comment('Повышенная ставка для этого правила (п. 6.4.4), доля от 1; NULL — общая ставка П3 из приказа');
            $table->date('starts_on')->comment('С какого дня позиция в перечне (п. 6.4.3 — со дня поступления первой партии)');
            $table->date('ends_on')->nullable()->comment('По какой день включительно; NULL — бессрочно');
            $table->string('order_number', 64)->nullable()->comment('Номер приказа, которым включено');
            $table->date('order_date')->nullable()->comment('Дата приказа');
            $table->string('comment', 500)->nullable()->comment('Зачем включили: вывод новинки, распродажа остатков, поддержка марки');
            $table->foreignId('author_id')->nullable()->comment('Кто завёл правило (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->index(['scope', 'target_id'], 'motivation_focus_rules_target_idx');
            $table->index(['starts_on', 'ends_on'], 'motivation_focus_rules_period_idx');
        });

        Schema::create('motivation_focus_snapshot_items', function (Blueprint $table) {
            $table->comment('Состав Фокус-перечня на расчётный период: развёрнутые позиции со ставкой, действовавшей в этом месяце');
            $table->id()->comment('Первичный ключ');
            $table->date('period_month')->comment('Расчётный период (1-е число месяца)');
            $table->foreignId('product_id')->comment('Позиция перечня (products.id)')->constrained('products')->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->comment('По какому правилу позиция попала в перечень (motivation_focus_rules.id)')->constrained('motivation_focus_rules')->nullOnDelete();
            $table->decimal('rate', 8, 5)->comment('Действовавшая в этом периоде ставка по позиции, доля от 1');
            $table->timestamp('created_at')->nullable()->comment('Когда снимок сделан');

            $table->unique(['period_month', 'product_id'], 'motivation_focus_snapshot_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_focus_snapshot_items');
        Schema::dropIfExists('motivation_focus_rules');
    }
};

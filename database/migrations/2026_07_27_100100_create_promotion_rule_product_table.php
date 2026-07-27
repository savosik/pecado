<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Материализованный список товаров-участников правила акции.
 *
 * Селекторы условий (категории с потомками, бренды, теги, ERP-промо) раскрываются
 * в плоский список джобой RecalculatePromotionRuleProductsJob. Нужен, чтобы каталог
 * дёшево показывал бейдж «участвует в акции» и фильтровал по нему без разбора JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_rule_product', function (Blueprint $table) {
            $table->comment('Товары-участники правил акций (материализация селекторов)');

            $table->foreignId('promotion_rule_id')->comment('Правило акции (promotion_rules.id)')
                ->constrained('promotion_rules')->cascadeOnDelete();
            $table->foreignId('product_id')->comment('Товар (products.id)')
                ->constrained('products')->cascadeOnDelete();
            $table->string('role', 16)
                ->comment("Роль товара в правиле: 'condition' — участвует в условии, 'reward' — выдаётся как награда");

            $table->primary(['promotion_rule_id', 'product_id', 'role'], 'promotion_rule_product_primary');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_rule_product');
    }
};

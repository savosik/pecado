<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выбор клиента по наградам акции внутри корзины.
 *
 * Сами промо-строки в БД не хранятся — движок вычисляет их на каждый рендер корзины
 * (по образцу резерва партии брака в DefectStockService). Хранится только то, что
 * вычислить нельзя: какую из нескольких наград клиент выбрал и от какой платной
 * промо-позиции отказался.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_promotion_selections', function (Blueprint $table) {
            $table->comment('Выбор клиента по наградам акции в корзине (сами промо-строки не хранятся)');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('cart_id')->comment('Корзина (carts.id)')
                ->constrained('carts')->cascadeOnDelete();
            $table->foreignId('promotion_rule_id')->comment('Правило акции (promotion_rules.id)')
                ->constrained('promotion_rules')->cascadeOnDelete();
            $table->unsignedSmallInteger('reward_index')
                ->comment('Порядковый номер награды в массиве rewards правила, начиная с 0');

            $table->foreignId('product_id')->nullable()
                ->comment('Выбранный клиентом товар награды (products.id); NULL — награда без выбора')
                ->constrained('products')->cascadeOnDelete();
            $table->boolean('is_declined')->default(false)
                ->comment('Клиент отказался от отклоняемой платной промо-позиции');

            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');

            $table->unique(['cart_id', 'promotion_rule_id', 'reward_index'], 'cart_promotion_selections_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_promotion_selections');
    }
};

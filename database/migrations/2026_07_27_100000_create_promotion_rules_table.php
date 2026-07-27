<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правила акций — механика конструктора промо.
 *
 * Правило описывает условие срабатывания (порог по количеству или сумме корзины)
 * и награду (промо-позиция по заданной цене). Контентная часть акции остаётся
 * в `promotions`; правило может существовать и без страницы (promotion_id = NULL).
 *
 * Волна 1 запускается целиком в режиме `info`: правила настраиваются и показываются,
 * но промо-позиции не выдаются — см. docs/promo-constructor-roadmap.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->comment('Правила акций: условие срабатывания + награда (промо-позиция)');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('promotion_id')->nullable()
                ->comment('Акция-лендинг (promotions.id); NULL — служебное правило без страницы')
                ->constrained('promotions')->nullOnDelete();

            $table->string('name')->comment('Название правила для админки');
            $table->boolean('is_active')->default(false)->comment('Правило включено');
            $table->string('mode')->default('info')
                ->comment("Режим: 'info' — только показываем срабатывание, 'issue' — выдаём промо-позиции");

            $table->timestamp('starts_at')->nullable()
                ->comment('Начало периода действия; NULL — без ограничения снизу');
            $table->timestamp('ends_at')->nullable()
                ->comment('Конец периода действия; NULL — без ограничения сверху');

            $table->unsignedSmallInteger('priority')->default(0)
                ->comment('Приоритет при конфликте правил, больше — важнее');
            $table->boolean('stackable')->default(true)
                ->comment('Можно ли применять правило вместе с другими');

            $table->json('conditions')
                ->comment('Условия срабатывания: {mode: all|any, items: [{selector, aggregate, price_basis, operator, value}]}; пороги сумм всегда в рублях');
            $table->json('rewards')
                ->comment('Награды (промо-позиции): [{type, product_id, choices, quantity, price, promo_kind, warehouse_id, multiply, per_value, max_multiplier, optional}]; цена в рублях');
            $table->json('audience')->nullable()
                ->comment('Ограничения аудитории: {region_ids, user_ids, manager_ids, channels}; NULL или пустые списки — без ограничений');
            $table->json('limits')->nullable()
                ->comment('Лимиты выдачи: {per_client_total, total}; NULL — без лимитов');

            $table->timestamp('created_at')->nullable()->comment('Дата создания правила');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');
            $table->softDeletes()->comment('Мягкое удаление: правило уходит в архив, но остаётся в истории заказов');

            // Выборка активных правил на каждый расчёт корзины
            $table->index(['is_active', 'starts_at', 'ends_at'], 'promotion_rules_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_rules');
    }
};

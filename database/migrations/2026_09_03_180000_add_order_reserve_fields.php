<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Режим «Заказы в резерве» (эпик res-00, протокол v16.9.0, топик №5 Agent Hub).
 *
 * Поля резерва на заказе, реплика признака участника на партнёре и таблица
 * точечных отклонений сайта (по принципу «только отклонения от умолчания»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('reserve')->default(false)->after('type')
                ->comment('Заказ в режиме резерва (v16.9.0): товар удержан в 1С, в сборку не идёт до подтверждения клиентом. Статуса «Резерв» в 1С нет — режим определяется только этим флагом');
            $table->timestamp('reserved_until')->nullable()->after('reserve')
                ->comment('Фактический срок удержания резерва из 1С (может быть урезан их пределом, стартово 24 ч). По истечении сайт шлёт order.deleted с reason=reserve_expired');
            $table->unsignedInteger('items_version')->nullable()->after('reserved_until')
                ->comment('Версия состава заказа из 1С: растёт только при изменении строк (в отличие от revision). База оптимистичной блокировки правок клиента (base_items_version)');

            $table->index(['reserve', 'reserved_until'], 'orders_reserve_expiry_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('reserve_allowed')->default(false)->after('client_status_id')
                ->comment('Участник режима «Заказы в резерве» — реплика реквизита партнёра из 1С (мастер флага — 1С, приходит в partner.created/partner.updated). Сайт может только сужать охват через order_reserve_overrides');
        });

        Schema::create('order_reserve_overrides', function (Blueprint $table) {
            $table->comment('Точечные отклонения режима «Заказы в резерве» по партнёру (только отклонения от умолчания; нет строки = действуют умолчания: участие по флагу 1С, срок из config/order_reserve.php)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->unique()->comment('Партнёр (users.id)')
                ->constrained()->cascadeOnDelete();
            $table->boolean('disabled')->default(false)
                ->comment('Резерв отключён на сайте для этого партнёра (сужение поверх флага 1С; рычаг РОПа против злоупотреблений)');
            $table->unsignedSmallInteger('hours')->nullable()
                ->comment('Индивидуальный срок резерва в часах (null — срок из конфига). Фактический срок всё равно ограничен пределом удержания 1С');
            $table->foreignId('created_by')->nullable()->comment('Кто завёл отклонение (users.id, сотрудник)')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Создано');
            $table->timestamp('updated_at')->nullable()->comment('Обновлено');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_reserve_overrides');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reserve_allowed');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_reserve_expiry_index');
            $table->dropColumn(['reserve', 'reserved_until', 'items_version']);
        });
    }
};

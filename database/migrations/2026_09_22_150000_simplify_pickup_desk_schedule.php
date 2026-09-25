<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стойка выдачи (pick-18), упрощение по решению заказчика 22.09.2026: клиенту и курьеру важно
 * только «работаем ли и когда перерывы». Смена, сотрудники и их личные перерывы убраны — начальник
 * склада правит одну таблицу «день недели → часы и перерывы выдачи», она же показывается курьеру.
 *
 * Отлучки «Отойти» остаются, но без привязки к сотруднику: нажал — стойка закрыта до срока.
 * Таблица отлучек пересоздаётся без staff_id (SQLite в тестах не умеет снимать внешний ключ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pickup_desk_pauses');
        Schema::dropIfExists('pickup_desk_breaks');
        Schema::dropIfExists('pickup_desk_staff');

        Schema::create('pickup_desk_days', function (Blueprint $table) {
            $table->comment('График стойки выдачи самовывоза по дням недели: часы работы и технические перерывы, как их видят курьер и клиент');
            $table->id()->comment('Первичный ключ');
            $table->unsignedTinyInteger('iso_weekday')->unique()->comment('День недели по ISO: 1 — пн … 7 — вс');
            $table->boolean('works')->default(true)->comment('Выдаёт ли склад в этот день; false — выходной');
            $table->time('opens_at')->nullable()->comment('Открытие выдачи; пусто — из config/warehouse.php');
            $table->time('closes_at')->nullable()->comment('Закрытие выдачи; пусто — из config/warehouse.php');
            $table->json('breaks')->comment('Технические перерывы: [{"from":"13:00","to":"14:00","label":"обед"}], когда на стойке никого');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения записи');
        });

        Schema::create('pickup_desk_pauses', function (Blueprint $table) {
            $table->comment('Живые отлучки со стойки выдачи: кладовщик нажал «Отойти» на экране выдачи — стойка закрыта до срока');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->nullable()->comment('Кто нажал кнопку (users.id); по нему отлучка попадает в журнал ссылки кладовщика')->constrained()->nullOnDelete();
            $table->string('reason', 60)->comment('Причина: обед, почта, отгрузка, другое — её видят курьер и клиент');
            $table->timestamp('started_at')->comment('Когда отошёл');
            $table->timestamp('until_at')->comment('Когда обещал вернуться; по истечении отлучка гаснет сама');
            $table->timestamp('ended_at')->nullable()->comment('Когда нажал «Вернулся»; пусто — ещё не вернулся или вышло время');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения записи');
            $table->index(['ended_at', 'until_at']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_desk_pauses');
        Schema::dropIfExists('pickup_desk_days');
    }
};

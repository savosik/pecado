<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стойка выдачи самовывоза (pick-18): кто на смене, плановые технические перерывы и живые «отошёл».
 *
 * Курьер и клиент должны знать, когда выдают, а когда нет. Выдача закрыта только если на стойке
 * никого: перерыв одного из двух сотрудников смены выдачу не останавливает. Документы сайта,
 * по шине с 1С не ходят.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_desk_staff', function (Blueprint $table) {
            $table->comment('Сотрудники стойки выдачи самовывоза: кто и в какие дни недели выдаёт заказы');
            $table->id()->comment('Первичный ключ');
            $table->string('name', 120)->comment('Имя сотрудника, как его видит склад');
            $table->foreignId('user_id')->nullable()->comment('Учётка сотрудника на сайте (users.id), если есть — с неё нажимают «Отойти»')->constrained()->nullOnDelete();
            $table->json('weekdays')->comment('Рабочие дни недели по ISO (1 — пн … 7 — вс), например [1,2,3,4,5]');
            $table->boolean('active')->default(true)->comment('Выведен ли сотрудник на стойку; выключенный в расчёте не участвует');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Порядок на экране');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения записи');
        });

        Schema::create('pickup_desk_breaks', function (Blueprint $table) {
            $table->comment('Плановые технические перерывы сотрудников стойки выдачи (обед, почта и т. п.)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('staff_id')->comment('Сотрудник (pickup_desk_staff.id)')->constrained('pickup_desk_staff')->cascadeOnDelete();
            $table->json('weekdays')->comment('Дни недели по ISO, в которые перерыв действует');
            $table->time('starts_at')->comment('Начало перерыва по времени склада');
            $table->time('ends_at')->comment('Конец перерыва по времени склада');
            $table->string('label', 60)->comment('Подпись перерыва для курьера и клиента: «обед», «почта»');
            $table->boolean('active')->default(true)->comment('Действует ли перерыв');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения записи');
            $table->index(['staff_id', 'active']);
        });

        Schema::create('pickup_desk_pauses', function (Blueprint $table) {
            $table->comment('Живые отлучки со стойки выдачи: кладовщик нажал «Отойти» на экране выдачи');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('staff_id')->nullable()->comment('Кто отошёл (pickup_desk_staff.id); пусто — стойка закрыта целиком')->constrained('pickup_desk_staff')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->comment('Кто нажал кнопку (users.id)')->constrained()->nullOnDelete();
            $table->string('reason', 60)->comment('Причина: обед, почта, отгрузка, другое');
            $table->timestamp('started_at')->comment('Когда отошёл');
            $table->timestamp('until_at')->comment('Когда обещал вернуться; по истечении отлучка гаснет сама');
            $table->timestamp('ended_at')->nullable()->comment('Когда нажал «Вернулся»; пусто — ещё не вернулся или вышло время');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения записи');
            $table->index(['ended_at', 'until_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_desk_pauses');
        Schema::dropIfExists('pickup_desk_breaks');
        Schema::dropIfExists('pickup_desk_staff');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Инсайд по закупкам партнёров у конкурента (группа «Андрей»).
 *
 * Разовая выгрузка продаж конкурента сопоставляется с нашими партнёрами по
 * наименованию и хранится агрегатом за окно: сколько партнёр берёт у него,
 * сколько документов, когда брал последний раз. Нужна Пулу и карточке партнёра,
 * чтобы «холодная» карточка отличалась от «покупает у конкурента на 10 млн в год».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_competitor_purchases', function (Blueprint $table) {
            $table->comment('Закупки партнёра у конкурента по инсайдерской выгрузке: агрегат за окно, сопоставление по наименованию');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Наш партнёр (users.id)')->constrained('users')->cascadeOnDelete();
            $table->string('source', 30)->comment("Источник: 'andrey' — группа «Андрей», выгрузка УТ");
            $table->text('competitor_partner')->comment('Наименование партнёра в базе конкурента, по которому сопоставлено');
            $table->decimal('amount', 15, 2)->comment('Сумма закупок у конкурента за окно, ₽');
            $table->unsignedInteger('documents')->comment('Документов реализации за окно');
            $table->date('first_purchase_on')->nullable()->comment('Первая закупка в окне');
            $table->date('last_purchase_on')->nullable()->comment('Последняя закупка в окне');
            $table->date('period_from')->comment('Начало окна выгрузки');
            $table->date('period_to')->comment('Конец окна выгрузки (включительно)');
            $table->timestamp('imported_at')->comment('Когда импортировано');
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->unique(['user_id', 'source'], 'crm_competitor_purchases_user_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_competitor_purchases');
    }
};

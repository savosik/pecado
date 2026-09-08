<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Новизна партнёра и история его закрепления (эпик mot-00, карточка mot-20).
 *
 * Новизна — кэш, а не истина: истина в отгрузках. Кэш нужен затем, что вопрос
 * «новый ли партнёр» задаётся на каждом экране и в каждом расчёте, а ответ на него
 * требует прохода по всей истории отгрузок партнёра.
 *
 * Флаг history_incomplete существует потому, что история отгрузок начинается 12.01.2026:
 * партнёр с первой отгрузкой в первом месяце выгрузки неотличим от партнёра с многолетней
 * историей закупок. На начисление флаг не влияет (решение заказчика от 08.09.2026:
 * отсутствие в истории приравнивается к отсутствию закупок), но экран обязан различать
 * «новый» и «выглядит новым, а проверить нельзя».
 *
 * Реестр закрепления не подменяет users.personal_manager_id — тот остаётся действующим
 * значением; реестр хранит историю, без которой атрибуция прошлых периодов идёт по
 * сегодняшнему состоянию и снимок перестаёт быть воспроизводимым.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_partner_novelty', function (Blueprint $table) {
            $table->comment('Кэш новизны партнёра: первая отгрузка, перерыв в закупках, границы Периода новизны');
            $table->foreignId('user_id')->primary()->comment('Партнёр (users.id) — первичный ключ, одна строка на партнёра')->constrained('users')->cascadeOnDelete();
            $table->date('first_shipment_on')->nullable()->comment('Первая отгрузка в доступной истории; NULL — не покупал ни разу');
            $table->date('last_shipment_before_gap_on')->nullable()->comment('Последняя отгрузка перед перерывом, давшим право на признание Новым');
            $table->unsignedInteger('gap_days')->nullable()->comment('Длительность перерыва в закупках, календарных дней');
            $table->date('novelty_started_on')->nullable()->comment('Начало Периода новизны: отгрузки партнёра в нём идут в показатель П2, а не П1');
            $table->date('novelty_ends_on')->nullable()->comment('Конец Периода новизны включительно');
            $table->string('source', 20)->nullable()->comment("Откуда пришёл партнёр: 'pool' — из Пула пакетом, 'own' — привлечён работником, 'unknown' — установить нельзя");
            $table->boolean('history_incomplete')->default(false)->comment('Перерыв не подтверждается из-за глубины истории: первая отгрузка попадает в первые месяцы выгрузки из 1С. На начисление не влияет, экран обязан помечать');
            $table->timestamp('computed_at')->nullable()->comment('Когда кэш пересчитан');

            $table->index(['novelty_started_on', 'novelty_ends_on'], 'motivation_partner_novelty_period_idx');
        });

        Schema::create('motivation_partner_assignments', function (Blueprint $table) {
            $table->comment('История закрепления партнёров за менеджерами (пп. 2.6, 5.5, 8.1 Положения): кто вёл партнёра в каждом периоде');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Партнёр (users.id)')->constrained('users')->cascadeOnDelete();
            $table->foreignId('personal_manager_id')->nullable()->comment('За кем закреплён (personal_managers.id); NULL — партнёр в Пуле')->constrained('personal_managers')->nullOnDelete();
            $table->date('starts_on')->comment('С какой даты действует закрепление. Переход применяется с 1-го числа следующего расчётного периода: отгрузки внутри месяца не дробятся');
            $table->date('ends_on')->nullable()->comment('По какую дату действовало включительно; NULL — действует сейчас');
            $table->string('reason', 20)->comment("Основание: 'initial' — бэкфилл текущего состояния, 'pool_package' — выдача пакетом из Пула, 'transfer' — передача между менеджерами, 'return_to_pool' — возврат в Пул, 'absence' — замещение на время отсутствия");
            $table->decimal('plan_delta', 15, 2)->nullable()->comment('Изменение Личного плана по п. 5.5 в связи с изменением состава базы, ₽');
            $table->string('comment', 500)->nullable()->comment('Пояснение к записи');
            $table->foreignId('author_id')->nullable()->comment('Кто внёс запись (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->index(['user_id', 'starts_on'], 'motivation_partner_assignments_user_idx');
            $table->index(['personal_manager_id', 'starts_on'], 'motivation_partner_assignments_manager_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_partner_assignments');
        Schema::dropIfExists('motivation_partner_novelty');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личные отборы списка клиентов.
 *
 * Отдельная таблица, а не общая с `crm_analytics_filter_presets`: там снимок
 * фильтров отчёта (даты, бренды, товары), здесь — рабочего списка (стадия,
 * состояние задач, план). Общая таблица означала бы `section`-колонку и вечную
 * проверку «а этот payload точно от этого раздела».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_client_filter_presets', function (Blueprint $table) {
            $table->comment('Личные отборы списка клиентов CRM (у каждого сотрудника свои)');
            $table->id()->comment('Первичный ключ');
            // Комментарий ставится до constrained(): после него Blueprint уже
            // закрыл описание колонки, и ->comment() ушёл бы в никуда.
            $table->foreignId('user_id')
                ->comment('Владелец отбора (users.id)')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name', 80)->comment('Название отбора, заданное сотрудником');
            $table->json('payload')->comment('Снимок фильтров списка: поиск, менеджер, стадия, состояние задач, состояние плана, порог неактивности, сортировка');
            $table->timestamp('created_at')->nullable()->comment('Когда отбор сохранён');
            $table->timestamp('updated_at')->nullable()->comment('Когда отбор последний раз изменён');

            $table->index(['user_id', 'created_at'], 'crm_client_presets_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_client_filter_presets');
    }
};

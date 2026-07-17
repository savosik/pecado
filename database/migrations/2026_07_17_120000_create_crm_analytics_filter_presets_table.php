<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_analytics_filter_presets', function (Blueprint $table) {
            $table->comment('Сохранённые пресеты фильтров отчёта продаж CRM (личные, у каждого сотрудника свои)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('Владелец пресета (users.id)');
            $table->string('name')->comment('Название пресета, заданное пользователем');
            $table->json('payload')->comment('Снимок фильтров: даты, id менеджеров/контрагентов/брендов/категорий, выбранные товары, режим и смещение сравнения');
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'crm_presets_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_analytics_filter_presets');
    }
};

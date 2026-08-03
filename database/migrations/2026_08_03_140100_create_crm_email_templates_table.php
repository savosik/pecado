<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заготовки писем для менеджеров.
 *
 * Подстановки — простые плейсхолдеры вида {{client_name}}, раскрываемые на сервере
 * при выборе шаблона. Полноценного шаблонизатора здесь сознательно нет: тело письма
 * пишет человек, и Blade в поле ввода означал бы выполнение кода из базы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_templates', function (Blueprint $table) {
            $table->comment('Заготовки писем CRM с подстановками {{client_name}}, {{manager_name}}');

            $table->id()->comment('Первичный ключ');

            $table->string('name')->comment('Название заготовки — видно менеджеру в списке');
            $table->string('subject')->comment('Тема письма с подстановками');
            $table->longText('body_html')->comment('Тело письма в HTML с подстановками');
            $table->boolean('is_active')->default(true)
                ->comment('Показывать ли заготовку менеджерам');

            $table->timestamp('created_at')->nullable()->comment('Когда создана заготовка');
            $table->timestamp('updated_at')->nullable()->comment('Когда последний раз изменена');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_templates');
    }
};

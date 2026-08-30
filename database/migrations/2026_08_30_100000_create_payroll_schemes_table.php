<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Версии схемы расчёта зарплаты (эпик pay-00, карточка pay-01).
 *
 * Схема — какие компоненты дохода входят в расчёт и их умолчания. Старая версия
 * не правится: изменение = новая строка с датой начала действия, чтобы утверждённые
 * месяцы можно было перечитать по той схеме, по которой они считались.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_schemes', function (Blueprint $table) {
            $table->comment('Версии схемы расчёта зарплаты: какие компоненты входят и их умолчания');
            $table->id()->comment('Первичный ключ');
            $table->string('code', 40)->comment("Код схемы: 'sales' — отдел продаж; задел под другие отделы");
            $table->unsignedSmallInteger('version')->comment('Номер версии внутри кода; старые версии не правятся, только новая строка');
            $table->string('title')->comment('Название версии для экрана настроек');
            $table->date('effective_from')->comment('Первый месяц действия (1-е число); для месяца берётся последняя версия с датой не позже него');
            $table->json('components')->comment('Упорядоченный список компонентов: [{key, enabled, defaults}] — ключ из config/payroll.php, включён ли, умолчания параметров');
            $table->foreignId('author_id')->nullable()->comment('Кто создал версию (users.id)')->constrained('users')->nullOnDelete();
            $table->string('comment')->nullable()->comment('Что изменилось относительно прошлой версии');
            $table->timestamp('created_at')->nullable()->comment('Когда создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда менялась');

            $table->unique(['code', 'version'], 'payroll_schemes_code_version_uniq');
            $table->index(['code', 'effective_from'], 'payroll_schemes_code_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_schemes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал смены статусов расходного ордера (US-20).
 *
 * 1С присылает только ТЕКУЩИЙ статус — историю ведёт сайт. Без неё нельзя ответить
 * на главный вопрос склада: сколько ордер простоял на каждом этапе и какие документы
 * зависли в отборе.
 *
 * Строка пишется только при фактической смене статуса: 1С досылает документ целиком
 * при любом изменении, и без этой проверки журнал забился бы повторами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_issue_status_histories', function (Blueprint $table) {
            $table->comment('Журнал смены статусов расходных ордеров');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('goods_issue_id')->comment('Расходный ордер (goods_issues.id)')->constrained()->cascadeOnDelete();

            $table->string('from_status', 32)->nullable()
                ->comment('Статус до перехода. Пусто у первой записи — ордер только приехал из 1С');
            $table->string('to_status', 32)
                ->comment('Статус после перехода (значения — см. комментарий goods_issues.status)');
            $table->dateTime('changed_at')->index()
                ->comment('Когда сайт зафиксировал переход');
            $table->string('source', 16)->default('erp')
                ->comment("Источник изменения: 'erp' — сообщение из 1С. Задел на ручные изменения со стороны склада");

            $table->timestamp('created_at')->nullable()->comment('Дата и время создания записи журнала');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения записи журнала');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_issue_status_histories');
    }
};

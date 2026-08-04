<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал звонков менеджеров.
 *
 * Телефония в проекте не подключена, и звонок сейчас заводится руками — но колонки
 * под АТС (`provider`, `external_id`, `duration_sec`, `recording_url`) заведены сразу.
 * Добавлять их второй миграцией, когда провайдер появится, означало бы менять форму
 * записи, payload и фронт разом ради полей, которые заранее известны.
 *
 * `unique(provider, external_id)` — идемпотентность будущего вебхука АТС: повтор
 * доставки не должен создавать второй звонок. У ручных записей provider = 'manual',
 * а external_id пуст, и NULL-ы в MySQL уникальность не нарушают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_calls', function (Blueprint $table) {
            $table->comment('Журнал звонков менеджеров: результат разговора и задел под интеграцию с АТС (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('user_id')
                ->comment('Сотрудник, который звонил или принял звонок (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('client_user_id')
                ->nullable()
                ->comment('Клиент, в ленту которого попадёт звонок (users.id); NULL — звонок не сводится к клиенту')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('related_type')->nullable()
                ->comment('Класс привязанной сущности (App\\Models\\User|Order|Shipment); NULL — звонок без привязки');
            $table->unsignedBigInteger('related_id')->nullable()
                ->comment('ID привязанной сущности в её таблице');

            $table->string('direction', 10)->default('outgoing')
                ->comment("Направление: 'outgoing' — исходящий, 'incoming' — входящий");

            $table->string('result', 20)->default('talked')
                ->comment("Итог: 'talked' — поговорили, 'no_answer' — не ответил, 'busy' — занято, 'wrong_number' — неверный номер, 'callback' — просили перезвонить");

            $table->string('phone', 32)->nullable()
                ->comment('Номер, по которому звонили — снимок на момент звонка (users.phone перезаписывает 1С)');

            $table->string('contact_name')->nullable()
                ->comment('С кем говорили, если это не основное контактное лицо');

            $table->text('summary')->nullable()
                ->comment('О чём договорились — текст, попадающий в ленту клиента');

            $table->timestamp('started_at')->nullable()
                ->comment('Когда состоялся разговор; NULL — время не указано, берётся created_at');

            $table->unsignedInteger('duration_sec')->nullable()
                ->comment('Длительность разговора в секундах; заполняется интеграцией с АТС');

            $table->string('provider', 30)->nullable()
                ->comment("Источник записи: 'manual' — заведено вручную, иначе код АТС");

            $table->string('external_id', 100)->nullable()
                ->comment('Идентификатор звонка в АТС — для сверки и защиты от дублей при повторной доставке');

            $table->string('recording_url', 500)->nullable()
                ->comment('Ссылка на запись разговора в АТС');

            $table->foreignId('follow_up_task_id')
                ->nullable()
                ->comment('Задача, поставленная по итогам звонка (crm_tasks.id); NULL — следующий шаг не назначен')
                ->constrained('crm_tasks')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');
            $table->softDeletes()->comment('Мягкое удаление: звонок — рабочая запись, восстановимость важнее чистоты');

            $table->index(['client_user_id', 'started_at'], 'crm_calls_client_started_idx');
            $table->index(['user_id', 'started_at'], 'crm_calls_user_started_idx');
            $table->index(['related_type', 'related_id'], 'crm_calls_related_idx');
            $table->unique(['provider', 'external_id'], 'crm_calls_provider_external_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_calls');
    }
};

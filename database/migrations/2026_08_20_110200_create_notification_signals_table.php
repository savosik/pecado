<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал входящих сигналов пульта: что произошло и что движок с этим сделал.
 *
 * Отвечает на вопрос «почему клиенту ничего не пришло» — включая случай, когда
 * не совпало ни одно правило. Существующий журнал писем sent_emails на такое
 * ответить не может по построению: он пишется по факту отправки.
 *
 * Пишется на каждое событие, поэтому ретенция обязательна (30 дней): прецедент
 * Pulse, чьи таблицы занимали 5,8 ГБ из 6,6 ГБ боевой базы, слишком свеж.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_signals', function (Blueprint $table) {
            $table->comment('Журнал входящих сигналов пульта уведомлений: что произошло, по какому партнёру и что движок с этим сделал');

            $table->id()->comment('Первичный ключ');
            $table->uuid('uuid')->unique()->comment('Идентификатор сигнала: по нему сшиваются все доставки одного события');
            $table->string('event_key', 64)->comment('Событие реестра, породившее сигнал');

            $table->foreignId('client_user_id')->nullable()
                ->comment('Партнёр, к жизни которого относится событие (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()
                ->comment('Контрагент события (companies.id)')
                ->constrained('companies')->nullOnDelete();

            $table->nullableMorphs('subject');

            $table->json('data')->comment('Нормализованный контекст события: поля, доступные условиям правил');
            $table->json('tags')->nullable()->comment('Метки события для условий «содержит»: инн:…, событие:…, просрочка:60+');
            $table->json('view')->nullable()->comment('Данные для вёрстки письма: заголовок, блоки изменений, ссылка');

            $table->unsignedSmallInteger('matched_rules_count')->default(0)->comment('Сколько правил совпало');
            $table->unsignedSmallInteger('deliveries_count')->default(0)->comment('Сколько писем поставлено в очередь');
            $table->boolean('dry_run')->default(false)->comment('Прогон без отправки: предпросмотр в интерфейсе или теневой режим перехода');
            $table->string('mode', 10)->default('live')->comment("Режим обработки: 'live' — с отправкой, 'shadow' — только расчёт получателей, 'off' — движок выключен");

            $table->timestamp('occurred_at')->nullable()->comment('Когда событие фактически произошло — по нему работает возрастной ценз');
            $table->timestamp('created_at')->nullable()->comment('Когда сигнал принят');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');

            $table->index(['event_key', 'created_at'], 'notification_signals_event_idx');
            $table->index(['client_user_id', 'created_at'], 'notification_signals_client_idx');
        });

        // nullableMorphs() комментарии не проставляет, а аудит требует покрытия
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `notification_signals` MODIFY `subject_type` VARCHAR(255) NULL COMMENT 'Класс сущности события (App\\\\Models\\\\Order и т.п.)'");
            DB::statement("ALTER TABLE `notification_signals` MODIFY `subject_id` BIGINT UNSIGNED NULL COMMENT 'Идентификатор сущности события'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_signals');
    }
};

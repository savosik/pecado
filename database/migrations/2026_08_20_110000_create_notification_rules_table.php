<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Правила маршрутизации уведомлений — ядро пульта.
 *
 * До них получатель письма был зашит в код: персональный менеджер считался
 * в OrderManagerRouting, резервные адреса лежали в ENV, список статусов заказа
 * для клиента — константой в config/notifications.php. Места, где видно
 * «кто и почему получает письмо», не существовало.
 *
 * Разбор идёт как в почтовых фильтрах: правила упорядочены приоритетом,
 * срабатывают все совпавшие, флаг stop_processing прерывает дальнейший разбор,
 * причём получатели самого правила при этом добавляются («сделай своё действие
 * и не смотри дальше»). Это позволяет выразить и «дополнительно», и «вместо».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->comment('Правило маршрутизации уведомлений: событие + условия + получатели. Правила упорядочены приоритетом и обрабатываются как почтовые фильтры: срабатывают все совпавшие, флаг stop_processing прерывает дальнейший разбор');

            $table->id()->comment('Первичный ключ');
            $table->string('name', 191)->comment('Название правила для списка в пульте');
            $table->text('description')->nullable()->comment('Пояснение менеджера: зачем правило заведено');

            $table->string('event_key', 64)->comment("Событие из реестра (config/notification_pulse.php), напр. 'orders.status_changed'. Допустима маска домена 'orders.*' и '*' — все события");

            $table->string('scope_type', 20)->default('global')->comment("Область: 'global' — все партнёры, 'user' — конкретный партнёр, 'company' — конкретный контрагент, 'manager' — все клиенты персонального менеджера");
            $table->foreignId('scope_user_id')->nullable()
                ->comment('Партнёр области (users.id), если scope_type = user')
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('scope_company_id')->nullable()
                ->comment('Контрагент области (companies.id), если scope_type = company')
                ->constrained('companies')->cascadeOnDelete();
            $table->foreignId('scope_manager_id')->nullable()
                ->comment('Персональный менеджер области (personal_managers.id), если scope_type = manager')
                ->constrained('personal_managers')->cascadeOnDelete();

            $table->json('conditions')->nullable()->comment('Дерево условий: {"all":[{"field":"status","op":"in","value":["closed"]}]}. NULL — правило срабатывает на любое событие своего типа');

            $table->unsignedSmallInteger('priority')->default(100)->comment('Порядок применения: меньше — раньше. Системные правила живут в 400-600, пользовательские по умолчанию 100');
            $table->boolean('stop_processing')->default(false)->comment('Прервать разбор следующих правил после этого. Получатели самого правила при этом добавляются');
            $table->boolean('is_active')->default(true)->comment('Включено ли правило');
            $table->boolean('is_system')->default(false)->comment('Системное правило: воспроизводит зашитое в код поведение, удалить нельзя — только выключить или переопределить');
            $table->string('system_key', 64)->nullable()->unique()->comment("Ключ системного правила для идемпотентной синхронизации сидером, напр. 'sys.orders.status_changed.client'");
            $table->string('preset_key', 64)->nullable()->comment('Пресет, из которого правило создано — по нему массовое применение не плодит дубли');

            $table->string('channel', 20)->default('email')->comment("Канал доставки: 'email' сейчас; 'telegram', 'push' — задел");
            $table->string('template_key', 64)->nullable()->comment('Шаблон письма (resources/views/mail/pulse/*); NULL — шаблон события по умолчанию');
            $table->string('subject_override', 512)->nullable()->comment('Своя тема письма вместо темы события; поддерживает плейсхолдеры вида {{order_number}}');
            $table->boolean('attach_documents')->default(false)->comment('Прикладывать связанные печатные формы файлом (счёт к сроку оплаты); при превышении лимита размера в письмо уходит ссылка');

            $table->unsignedInteger('throttle_seconds')->nullable()->comment('Не слать одному адресату по этому правилу чаще, чем раз в N секунд; NULL — без ограничения');
            $table->string('digest', 10)->default('none')->comment("Сведение писем: 'none' — сразу, 'hourly' — раз в час, 'daily' — раз в сутки (задел, реализуется в notif-12)");
            $table->json('quiet_hours')->nullable()->comment('Тихие часы {"from":"22:00","to":"08:00"} — письмо откладывается до конца окна (задел, notif-12)');

            $table->timestamp('last_matched_at')->nullable()->comment('Когда правило последний раз совпало — по нему видно мёртвые правила');
            $table->unsignedBigInteger('matched_count')->default(0)->comment('Сколько раз правило совпадало — счётчик наблюдаемости');

            $table->foreignId('created_by_user_id')->nullable()
                ->comment('Кто создал правило (users.id)')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()
                ->comment('Кто последним изменил правило (users.id)')->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда правило создано');
            $table->timestamp('updated_at')->nullable()->comment('Когда правило последний раз менялось');
            $table->softDeletes();

            $table->index(['event_key', 'is_active', 'priority'], 'notification_rules_match_idx');
            $table->index(['scope_user_id', 'event_key'], 'notification_rules_user_idx');
            $table->index(['scope_company_id', 'event_key'], 'notification_rules_company_idx');
            $table->index(['scope_manager_id', 'event_key'], 'notification_rules_manager_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `notification_rules` MODIFY `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Мягкое удаление: журнал доставок ссылается на правило и должен показывать его название после удаления'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};

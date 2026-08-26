<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки уведомлений партнёра: что присылать и куда.
 *
 * Хранятся **только отклонения** от умолчания. Умолчания живут рядом с самим
 * уведомлением, в config/mail_occasions.php: добавил уведомление — сразу указал,
 * кому оно идёт по умолчанию, и расхождению неоткуда взяться.
 *
 * Поэтому 831 партнёр на 16 типов — это не тринадцать тысяч строк, а десяток
 * исключений: строка появляется, когда кто-то поменял настройку руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->comment('Настройки уведомлений партнёра; строка = отклонение от умолчания');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')
                ->comment('Партнёр, которому настраивают уведомления (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('occasion_key', 64)
                ->comment('Тип уведомления — ключ из config/mail_occasions.php, например orders.status_changed');
            $table->boolean('is_enabled')
                ->default(true)
                ->comment('false — «не присылать это вообще»; отписка выражается так, а не стоп-листом');
            $table->json('destinations')
                ->nullable()
                ->comment('Адресаты: [{"type":"login"},{"type":"contact_role","role":"accountant"}]; null — взять умолчание');
            $table->json('options')
                ->nullable()
                ->comment('Доп. настройки типа; сейчас только {"statuses":[...]} для смены статуса заказа');
            $table->boolean('changed_by_client')
                ->default(false)
                ->comment('Правил ли настройку сам партнёр в кабинете — в CRM показывается бейджем');
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->comment('Кто менял последним (users.id)')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда настройку завели');
            $table->timestamp('updated_at')->nullable()->comment('Когда настройку меняли последний раз');

            $table->unique(['user_id', 'occasion_key'], 'notification_preferences_unique');
            $table->index('occasion_key', 'notification_preferences_occasion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};

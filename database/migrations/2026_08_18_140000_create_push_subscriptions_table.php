<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Подписки браузеров на Web Push (task-09).
 *
 * Таблица пакета laravel-notification-channels/webpush, собранная своей
 * миграцией ради обязательных комментариев к столбцам. Несколько браузеров
 * одного менеджера = несколько строк; протухшие (410 Gone) пакет удаляет сам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('webpush.table_name', 'push_subscriptions'), function (Blueprint $table) {
            $table->comment('Подписки браузеров на push-уведомления (Web Push, VAPID)');
            $table->bigIncrements('id')->comment('Первичный ключ');
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)
                ->charset('ascii')
                ->unique()
                ->comment('URL push-сервиса браузера (FCM/Mozilla/…), уникален на подписку');
            $table->string('public_key')->nullable()->comment('Ключ p256dh подписки — шифрование payload');
            $table->string('auth_token')->nullable()->comment('Секрет auth подписки');
            $table->string('content_encoding')->nullable()->comment("Кодирование payload: обычно 'aes128gcm'");
            $table->timestamp('created_at')->nullable()->comment('Когда подписка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда подписка обновлялась');
        });

        // morphs() не даёт повесить комментарии — доводим отдельно (только MySQL).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $tableName = config('webpush.table_name', 'push_subscriptions');
            Schema::getConnection()->statement(
                "ALTER TABLE `{$tableName}`
                    MODIFY `subscribable_type` varchar(255) NOT NULL COMMENT 'Класс владельца подписки (App\\\\Models\\\\User)',
                    MODIFY `subscribable_id` bigint unsigned NOT NULL COMMENT 'Владелец подписки (users.id)'"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('webpush.table_name', 'push_subscriptions'));
    }
};

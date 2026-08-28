<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * note-10: подписки кабинета на изменения заказов заменены матрицей уведомлений.
 *
 * Живые email-подписки переносятся в `notification_preferences`: адрес становится
 * адресатом типа `email` у соответствующих типов уведомлений партнёра. Старый
 * механизм писал только на подписанный адрес, поэтому логин партнёра сюда не
 * добавляется. Уже настроенная строка партнёра не затирается — адрес добавляется.
 */
return new class extends Migration
{
    /** Тип события подписки → тип уведомления матрицы. */
    private const EVENT_TO_OCCASION = [
        'items_updated' => 'orders.items_updated',
        'attributes_updated' => 'orders.attributes_updated',
        'api_shortfall' => 'orders.shortfall',
    ];

    public function up(): void
    {
        if (Schema::hasTable('entity_subscriptions') && Schema::hasTable('notification_preferences')) {
            $this->carryOver();
        }

        Schema::dropIfExists('entity_subscriptions');
    }

    public function down(): void
    {
        Schema::create('entity_subscriptions', function (Blueprint $table) {
            $table->comment('Подписки на изменения сущностей раздела личного кабинета (снято в note-10, таблица восстановлена откатом)');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Владелец кабинета, создавший подписку (users.id)')
                ->constrained()->cascadeOnDelete();
            $table->string('section', 50)->comment("Раздел кабинета: 'orders' — заказы");
            $table->string('channel', 20)->default('email')->comment("Канал доставки: 'email' — письмо, 'telegram' — сообщение боту");
            $table->string('destination')->comment('Адресат: email-адрес или telegram chat_id (в зависимости от channel)');
            $table->json('events')->nullable()->comment('Типы событий раздела (JSON-массив ключей); NULL — все типы');
            $table->boolean('is_active')->default(true)->comment('Активна ли подписка (false — отписан, уведомления не шлём)');
            $table->string('unsubscribe_token', 64)->unique()->comment('Токен для отписки по ссылке из письма (без авторизации)');
            $table->timestamp('last_notified_at')->nullable()->comment('Момент последней успешной отправки уведомления по этой подписке');
            $table->timestamps();

            $table->unique(['user_id', 'section', 'channel', 'destination'], 'entity_subscriptions_unique');
            $table->index(['section', 'channel', 'is_active'], 'entity_subscriptions_lookup');
        });
    }

    private function carryOver(): void
    {
        $subscriptions = DB::table('entity_subscriptions')
            ->where('is_active', true)
            ->where('channel', 'email')
            ->where('section', 'orders')
            ->whereNotNull('destination')
            ->get();

        foreach ($subscriptions as $subscription) {
            $email = mb_strtolower(trim((string) $subscription->destination));

            if ($email === '') {
                continue;
            }

            $events = $subscription->events ? (array) json_decode((string) $subscription->events, true) : array_keys(self::EVENT_TO_OCCASION);

            foreach ($events as $event) {
                $occasionKey = self::EVENT_TO_OCCASION[$event] ?? null;

                if ($occasionKey === null) {
                    continue;
                }

                $this->addEmailDestination((int) $subscription->user_id, $occasionKey, $email);
            }
        }
    }

    private function addEmailDestination(int $userId, string $occasionKey, string $email): void
    {
        $row = DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->where('occasion_key', $occasionKey)
            ->first();

        $destinations = $row && $row->destinations
            ? (array) json_decode((string) $row->destinations, true)
            : [];

        $already = collect($destinations)->contains(
            fn ($destination) => ($destination['type'] ?? null) === 'email'
                && mb_strtolower(trim((string) ($destination['email'] ?? ''))) === $email,
        );

        if (! $already) {
            $destinations[] = ['type' => 'email', 'email' => $email];
        }

        $payload = [
            'is_enabled' => true,
            'destinations' => json_encode(array_values($destinations), JSON_UNESCAPED_UNICODE),
            'changed_by_client' => true,
            'updated_at' => now(),
        ];

        if ($row) {
            DB::table('notification_preferences')->where('id', $row->id)->update($payload);

            return;
        }

        DB::table('notification_preferences')->insert($payload + [
            'user_id' => $userId,
            'occasion_key' => $occasionKey,
            'options' => null,
            'created_at' => now(),
        ]);
    }
};

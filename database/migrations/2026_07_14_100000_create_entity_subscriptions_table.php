<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_subscriptions', function (Blueprint $table) {
            $table->comment('Подписки на изменения сущностей раздела личного кабинета (email/telegram)');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('Владелец кабинета, создавший подписку (users.id)');
            $table->string('section', 50)
                ->comment("Раздел кабинета: 'orders' — заказы, далее 'order-changes', 'shipments' и т.д.");
            $table->string('channel', 20)->default('email')
                ->comment("Канал доставки: 'email' — письмо, 'telegram' — сообщение боту");
            $table->string('destination')
                ->comment('Адресат: email-адрес или telegram chat_id (в зависимости от channel)');
            $table->boolean('is_active')->default(true)
                ->comment('Активна ли подписка (false — отписан, уведомления не шлём)');
            $table->string('unsubscribe_token', 64)->unique()
                ->comment('Токен для отписки по ссылке из письма (без авторизации)');
            $table->timestamp('last_notified_at')->nullable()
                ->comment('Момент последней успешной отправки уведомления по этой подписке');
            $table->timestamps();

            $table->unique(['user_id', 'section', 'channel', 'destination'], 'entity_subscriptions_unique');
            $table->index(['section', 'channel', 'is_active'], 'entity_subscriptions_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_subscriptions');
    }
};

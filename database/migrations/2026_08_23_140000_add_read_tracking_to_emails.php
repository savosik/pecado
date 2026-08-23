<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отслеживание прочтения писем.
 *
 * Счётчики живут на доставке, а не на письме: вопрос звучит «кто читает наши
 * письма», и ответ на него по письму целиком не даётся. У каждой доставки свой
 * токен, по нему и различаются адресаты.
 *
 * Открытие — сигнал, а не факт: почтовые клиенты режут картинки, а Apple и
 * Gmail, наоборот, подгружают их сами. Переход по ссылке сигнал куда более
 * честный, поэтому считается отдельно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->boolean('tracking_enabled')->default(true)->after('skip_reason')
                ->comment('Отслеживать открытия и переходы по ссылкам; выключается галочкой в письме');
        });

        Schema::table('crm_email_deliveries', function (Blueprint $table) {
            $table->string('channel', 4)->default('to')->after('recipient')
                ->comment("Как адресат указан в письме: 'to' — получатель, 'cc' — копия");

            $table->char('track_token', 40)->nullable()->unique()->after('channel')
                ->comment('Токен в ссылке пикселя и редиректа; по нему открытие связывается с адресатом');

            $table->timestamp('opened_at')->nullable()
                ->comment('Когда письмо открыли впервые; NULL — открытия не зафиксировано (не то же самое, что «не прочитали»)');
            $table->timestamp('last_opened_at')->nullable()->comment('Последнее открытие');
            $table->unsignedInteger('opens_count')->default(0)->comment('Сколько раз зафиксировано открытие');

            $table->timestamp('clicked_at')->nullable()
                ->comment('Первый переход по ссылке из письма — сигнал куда честнее открытия');
            $table->timestamp('last_clicked_at')->nullable()->comment('Последний переход');
            $table->unsignedInteger('clicks_count')->default(0)->comment('Сколько переходов зафиксировано');

            $table->index('opened_at', 'crm_email_deliveries_opened_idx');
        });

        Schema::create('crm_email_events', function (Blueprint $table) {
            $table->comment('События по отправленному письму: открытия и переходы по ссылкам');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('delivery_id')
                ->comment('Доставка письма конкретному адресату (crm_email_deliveries.id)')
                ->constrained('crm_email_deliveries')
                ->cascadeOnDelete();

            $table->string('type', 10)
                ->comment("Что произошло: 'open' — загружен пиксель, 'click' — переход по ссылке");

            $table->string('url', 1024)->nullable()
                ->comment('Куда перешли; для открытия пусто');

            $table->string('ip', 45)->nullable()
                ->comment('IP, с которого пришёл запрос — по нему видно проксирование почтового клиента');
            $table->string('user_agent', 512)->nullable()
                ->comment('User-Agent запроса: по нему отличается предзагрузка Apple и Gmail от живого человека');

            $table->timestamp('created_at')->nullable()->comment('Когда событие зафиксировано');

            $table->index(['delivery_id', 'type'], 'crm_email_events_delivery_type_idx');
            $table->index('created_at', 'crm_email_events_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_events');

        Schema::table('crm_email_deliveries', function (Blueprint $table) {
            $table->dropIndex('crm_email_deliveries_opened_idx');
            $table->dropColumn([
                'channel', 'track_token',
                'opened_at', 'last_opened_at', 'opens_count',
                'clicked_at', 'last_clicked_at', 'clicks_count',
            ]);
        });

        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropColumn('tracking_enabled');
        });
    }
};

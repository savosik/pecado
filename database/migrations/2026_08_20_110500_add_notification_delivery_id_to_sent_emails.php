<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь журнала писем с решением пульта.
 *
 * sent_emails отвечает «что ушло», notification_deliveries — «почему именно
 * этому адресату». Связка позволяет пройти путь целиком: от письма в почтовом
 * логе до правила, которое его породило.
 *
 * Механика проставления — копия работающего приёма MailClientTag: заголовок
 * X-Pecado-Delivery ставит уведомление, читает LogSentEmail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->foreignId('notification_delivery_id')->nullable()->after('source')
                ->comment('Доставка пульта уведомлений, породившая письмо (notification_deliveries.id); NULL — письмо не из пульта')
                ->constrained('notification_deliveries')->nullOnDelete();

            $table->index('notification_delivery_id', 'sent_emails_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->dropForeign(['notification_delivery_id']);
            $table->dropIndex('sent_emails_delivery_idx');
            $table->dropColumn('notification_delivery_id');
        });
    }
};

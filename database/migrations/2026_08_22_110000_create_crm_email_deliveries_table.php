<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кому какое письмо уже уходило.
 *
 * Слой, гарантирующий, что письмо с одним и тем же id не уйдёт на один и тот же
 * адрес дважды. Нужен именно отдельным слоем, потому что правила-фильтры
 * независимы и знать друг о друге не должны: два фильтра могут поймать одно
 * письмо и назвать один и тот же адрес, и это нормально — а вот два письма
 * клиенту это уже не нормально.
 *
 * Уникальный индекс, а не проверка в коде: два воркера очереди, взявшие
 * задание одновременно, проверку в коде проходят оба. База — единственное
 * место, где «уже отправляли» решается без гонки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_deliveries', function (Blueprint $table) {
            $table->comment('Факт отправки письма CRM конкретному адресу — защита от повторов');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('crm_email_id')
                ->comment('Письмо (crm_emails.id)')
                ->constrained('crm_emails')
                ->cascadeOnDelete();

            $table->string('recipient', 191)
                ->comment('Адрес получателя в нижнем регистре — по нему и идёт защита от повтора');

            $table->timestamp('sent_at')->nullable()
                ->comment('Момент подтверждённой сдачи письма транспорту; NULL — попытка была, результат неизвестен');

            $table->timestamp('created_at')->nullable()
                ->comment('Когда адрес был занят под отправку');

            // Гарантия «один адрес — одно письмо — один раз».
            $table->unique(['crm_email_id', 'recipient'], 'crm_email_deliveries_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_deliveries');
    }
};

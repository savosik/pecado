<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал писем, отправленных менеджерами из CRM.
 *
 * Письма уходят с общего ящика отдела (From), а личная почта менеджера ставится
 * в Reply-To: ответ клиента приходит менеджеру, но хранить пароли личных ящиков
 * и разбираться с SPF/DKIM по каждому из них не нужно.
 *
 * Статус 'draft' нужен по технической причине: вложение прикрепляется только
 * к сохранённой записи (MediaService не умеет загрузку «в никуда»), поэтому форма
 * сначала создаёт черновик, а «Отправить» переводит его в очередь.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_emails', function (Blueprint $table) {
            $table->comment('Журнал писем, отправленных менеджерами из CRM');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('user_id')
                ->comment('Автор письма — сотрудник (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('client_user_id')
                ->nullable()
                ->comment('Клиент, в ленту которого попадёт письмо (users.id); NULL — письмо не сводится к клиенту')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('related_type')->nullable()
                ->comment('Класс сущности, по поводу которой письмо (App\Models\User|Order|Shipment); NULL — без привязки');
            $table->unsignedBigInteger('related_id')->nullable()
                ->comment('ID привязанной сущности в её таблице');

            $table->json('to')->comment('Получатели: массив email-адресов');
            $table->json('cc')->nullable()->comment('Копия: массив email-адресов');
            $table->string('reply_to')->nullable()
                ->comment('Обратный адрес — личная почта менеджера, чтобы ответ клиента пришёл ему');

            $table->string('subject')->comment('Тема письма');
            $table->longText('body_html')->comment('Тело письма в HTML, как его составил менеджер');

            $table->string('status', 20)->default('draft')
                ->comment("Статус: 'draft' — черновик, 'queued' — в очереди, 'sent' — отправлено, 'failed' — ошибка");

            $table->timestamp('sent_at')->nullable()->comment('Момент фактической отправки через SMTP');
            $table->string('message_id')->nullable()
                ->comment('Message-ID из SMTP — по нему письмо ищется в логах почтового сервера');
            $table->text('error')->nullable()->comment('Текст ошибки, если отправка не удалась');

            $table->timestamp('created_at')->nullable()->comment('Когда создан черновик');
            $table->timestamp('updated_at')->nullable()->comment('Когда последний раз изменено');

            $table->index(['related_type', 'related_id'], 'crm_emails_related_idx');
            $table->index(['client_user_id', 'created_at'], 'crm_emails_client_created_idx');
            $table->index(['user_id', 'status'], 'crm_emails_author_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_emails');
    }
};

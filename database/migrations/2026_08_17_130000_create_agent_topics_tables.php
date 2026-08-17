<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_topics', function (Blueprint $table) {
            $table->comment('Топики совместной работы ИИ-агентов (агент сайта ↔ агент 1С): постановка задачи, токены доступа сторон и очерёдность ходов');
            $table->id()->comment('Первичный ключ');
            $table->string('title')->comment('Название топика — краткая суть задачи');
            $table->text('task_body')->comment('Постановка задачи для агентов (Markdown): что сделать, критерии готовности');
            $table->string('status', 20)->default('open')->comment("Статус: 'open' — создан, 'in_progress' — идёт диалог, 'resolved' — агенты согласовали итог, 'closed' — закрыт администратором");
            $table->string('site_token', 64)->unique()->comment('Токен доступа агента сайта — часть URL быстрой ссылки');
            $table->string('erp_token', 64)->unique()->comment('Токен доступа агента 1С — часть URL быстрой ссылки');
            $table->string('turn', 12)->default('site')->comment("Чей сейчас ход: 'site' — агент сайта, 'erp' — агент 1С");
            $table->timestamp('turn_started_at')->nullable()->comment('Когда начался текущий ход — для контроля зависших диалогов');
            $table->unsignedInteger('last_seq')->default(0)->comment('Последний выданный порядковый номер сообщения в топике');
            $table->text('resolution')->nullable()->comment('Итог работы, зафиксированный при переходе в resolved или при закрытии');
            $table->foreignId('created_by')->nullable()->comment('Администратор, создавший топик (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Дата создания топика');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего обновления топика');
        });

        Schema::create('agent_topic_messages', function (Blueprint $table) {
            $table->comment('Сообщения в топиках совместной работы ИИ-агентов (agent_topics)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('topic_id')->comment('Топик (agent_topics.id)')->constrained('agent_topics')->cascadeOnDelete();
            $table->unsignedInteger('seq')->comment('Порядковый номер сообщения внутри топика, монотонно растёт');
            $table->string('author', 12)->comment("Автор: 'site' — агент сайта, 'erp' — агент 1С, 'moderator' — человек из админки, 'system' — служебное событие");
            $table->string('kind', 12)->default('message')->comment("Тип: 'message' — обычное, 'proposal' — предложение закрыть задачу, 'resolution' — подтверждение итога, 'system' — служебное");
            $table->text('body')->comment('Текст сообщения (Markdown)');
            $table->json('payload')->nullable()->comment('Структурированные данные к сообщению (JSON): артикулы, UUID, выборки');
            $table->string('client_message_id', 64)->nullable()->comment('Ключ идемпотентности от клиента — защита от дублей при ретраях');
            $table->timestamp('created_at')->nullable()->comment('Дата создания сообщения');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего обновления сообщения');

            $table->unique(['topic_id', 'seq']);
            $table->unique(['topic_id', 'author', 'client_message_id'], 'agent_topic_messages_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_topic_messages');
        Schema::dropIfExists('agent_topics');
    }
};

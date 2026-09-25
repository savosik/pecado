<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Помощник клиента в кабинете (эпик assist-00): треды, ходы, вложения,
 * подтверждения необратимых операций, заметка о клиенте и воронка событий.
 *
 * Переписка конфиденциальна: таблицы `chat_*` и `client_assistant_notes`
 * исключены из BI-грантов (BiSyncGrants::CONFIDENTIAL_TABLE_PREFIXES).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->comment('Треды чата-помощника: один разговор клиента с агентом');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Клиент — владелец треда (users.id)')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id')->nullable()->comment('Юрлицо, от имени которого идёт разговор (companies.id); NULL — ещё не выбрано');
            $table->foreignId('token_id')->nullable()->comment('Токен вида assistant, выпущенный на этот тред (api_tokens.id); NULL после отзыва')->constrained('api_tokens')->nullOnDelete();
            $table->string('title', 160)->nullable()->comment('Заголовок для списка: первые слова первой реплики или тема, которую дала модель');
            $table->string('status', 16)->default('open')->comment("Состояние: 'open' — идёт, 'closed' — закрыт (по бездействию или клиентом), после закрытия дописана заметка о клиенте");
            $table->json('page')->nullable()->comment('Контекст страницы, с которой открыт тред: {type, id, url, title}; уходит первым сообщением, в системный промпт не вписывается');
            $table->timestamp('last_message_at')->nullable()->comment('Когда был последний ход (для сортировки и закрытия по бездействию)');
            $table->timestamp('closed_at')->nullable()->comment('Когда закрыт');
            $table->timestamp('compacted_at')->nullable()->comment('Когда сервер Anthropic последний раз сжал историю (блок компакции сохранён отдельным ходом)');
            $table->unsignedBigInteger('input_tokens')->default(0)->comment('Сумма входных токенов по всем ходам (без кеша)');
            $table->unsignedBigInteger('output_tokens')->default(0)->comment('Сумма выходных токенов по всем ходам');
            $table->unsignedBigInteger('cache_read_tokens')->default(0)->comment('Сумма токенов, прочитанных из кеша промпта');
            $table->unsignedBigInteger('cache_write_tokens')->default(0)->comment('Сумма токенов, записанных в кеш промпта');
            $table->decimal('cost', 12, 6)->default(0)->comment('Стоимость треда в долларах по прайсу config/assistant.php');
            $table->text('summary')->nullable()->comment('Резюме закрытого треда для памяти клиента (пишет модель при закрытии)');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');

            $table->index(['user_id', 'last_message_at'], 'chat_threads_user_last_index');
            $table->index(['status', 'last_message_at'], 'chat_threads_status_last_index');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->comment('Ходы треда: реплики клиента, ответы модели и служебные сообщения — история append-only, блоки хранятся как есть');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('thread_id')->comment('Тред (chat_threads.id)')->constrained('chat_threads')->cascadeOnDelete();
            $table->string('role', 16)->comment("Роль: 'user' — клиент, 'assistant' — модель, 'system' — операторская инструкция посреди треда");
            $table->string('kind', 16)->default('message')->comment("Вид хода для показа: 'message' — обычная реплика, 'confirmation' — служебное «клиент подтвердил/отказался», 'note' — служебная отметка (недоступность, квота)");
            $table->json('content')->comment('Массив блоков в wire-формате Messages API (text, image, document, thinking, mcp_tool_use, mcp_tool_result, compaction…) — повторно отправляется байт-в-байт');
            $table->longText('text')->nullable()->comment('Плоский текст хода для показа и поиска (без служебных блоков)');
            $table->string('status', 16)->default('done')->comment("Состояние: 'pending' — в очереди, 'streaming' — модель отвечает, 'done' — готов, 'failed' — ошибка (см. error_code)");
            $table->string('error_code', 64)->nullable()->comment('Код ошибки при status=failed: billing, rate_limit, refusal, quota, gateway…');
            $table->json('usage')->nullable()->comment('usage ответа Anthropic как есть (input/output/cache токены)');
            $table->decimal('cost', 12, 6)->default(0)->comment('Стоимость хода в долларах');
            $table->string('anthropic_id', 64)->nullable()->comment('Идентификатор сообщения на стороне Anthropic (msg_…)');
            $table->string('model', 64)->nullable()->comment('Модель, которая отвечала (из ответа; может отличаться от запрошенной при фолбэке)');
            $table->string('stop_reason', 32)->nullable()->comment('Почему модель остановилась: end_turn, max_tokens, refusal…');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');

            $table->index(['thread_id', 'id'], 'chat_messages_thread_id_index');
        });

        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->comment('Файлы, прикреплённые клиентом к ходу: оригинал в хранилище, копия в Files API Anthropic для таблиц');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('thread_id')->comment('Тред (chat_threads.id)')->constrained('chat_threads')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->comment('Ход, к которому прикреплён файл (chat_messages.id); NULL — загружен, но ещё не отправлен')->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('user_id')->comment('Кто загрузил (users.id)')->constrained('users')->cascadeOnDelete();
            $table->string('disk', 32)->comment('Диск хранилища Laravel (s3 — MinIO)');
            $table->string('path', 255)->comment('Путь в хранилище');
            $table->string('original_name', 255)->comment('Имя файла, как загрузил клиент');
            $table->string('mime', 128)->comment('MIME-тип по содержимому файла');
            $table->unsignedInteger('size')->comment('Размер, байт');
            $table->string('kind', 16)->comment("Как уходит модели: 'image' — блок image, 'document' — блок document (PDF), 'table' — Files API + выполнение кода, 'text' — текстом");
            $table->string('anthropic_file_id', 64)->nullable()->comment('Идентификатор файла в Files API Anthropic (file_…), чтобы не загружать повторно');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');

            $table->index(['thread_id', 'message_id'], 'chat_attachments_thread_message_index');
        });

        Schema::create('chat_confirmations', function (Blueprint $table) {
            $table->comment('Подтверждения необратимых операций в чате: модель предложила — клиент нажал «Оформить» — операция выполнилась');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('thread_id')->comment('Тред (chat_threads.id)')->constrained('chat_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('Клиент (users.id)')->constrained('users')->cascadeOnDelete();
            $table->string('operation', 64)->comment('Операция реестра клиентского API: orders.create, checkout.submit, returns.create…');
            $table->json('arguments')->comment('Аргументы операции, как их передала модель — выполняются ровно они');
            $table->string('arguments_hash', 64)->comment('SHA-256 нормализованных аргументов: повторный вызов с теми же аргументами находит это подтверждение');
            $table->string('idempotency_key', 64)->unique()->comment('Ключ идемпотентности операции: выдаётся здесь, а не моделью, чтобы повтор не создал дубль');
            $table->string('status', 16)->default('pending')->comment("Состояние: 'pending' — ждёт клиента, 'approved' — клиент подтвердил, 'used' — операция выполнена, 'declined' — отказался, 'expired' — истекло");
            $table->json('summary')->nullable()->comment('Что показать в карточке подтверждения: состав, сумма, юрлицо (собирает сервер по аргументам)');
            $table->json('result')->nullable()->comment('Ответ операции после выполнения (номер заказа и т. п.)');
            $table->timestamp('decided_at')->nullable()->comment('Когда клиент нажал «Оформить» или «Отмена»');
            $table->timestamp('expires_at')->comment('До какого момента подтверждение действительно');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');

            $table->index(['thread_id', 'status'], 'chat_confirmations_thread_status_index');
            $table->index(['user_id', 'arguments_hash'], 'chat_confirmations_user_hash_index');
        });

        Schema::create('client_assistant_notes', function (Blueprint $table) {
            $table->comment('Заметка помощника о клиенте: память между тредами (как обращаться, что заказывает, предпочтения); пишет модель по закрытии треда');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->unique()->comment('Клиент (users.id), одна заметка на аккаунт')->constrained('users')->cascadeOnDelete();
            $table->text('content')->comment('Текст заметки в свободной форме, до нескольких КБ; токенов и паролей здесь быть не должно');
            $table->unsignedInteger('version')->default(1)->comment('Номер версии: растёт при каждой перезаписи');
            $table->string('updated_by', 16)->nullable()->comment("Кто последним менял: 'model' — модель по закрытии треда, 'staff' — сотрудник в CRM");
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');
        });

        Schema::create('chat_events', function (Blueprint $table) {
            $table->comment('Воронка помощника без текста: показы иконки и реплик, открытия, первые сообщения, подтверждённые действия, голосовой ввод');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Клиент (users.id)')->constrained('users')->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->comment('Тред, если событие внутри разговора (chat_threads.id)')->constrained('chat_threads')->nullOnDelete();
            $table->string('event', 32)->comment("Событие: 'shown' — иконка показана, 'bubble_shown' / 'bubble_clicked' / 'bubble_dismissed' — реплика, 'opened' — диалог открыт, 'first_message' — первое сообщение треда, 'confirmed_action' — подтверждена необратимая операция, 'voice_used' — голосовой ввод");
            $table->string('page', 64)->nullable()->comment('Тип страницы, где произошло событие: product, catalog, order, documents, finance, cabinet…');
            $table->string('prompt_key', 64)->nullable()->comment('Ключ реплики из каталога фраз (для bubble_*)');
            $table->timestamp('created_at')->nullable()->comment('Когда произошло');

            $table->index(['user_id', 'created_at'], 'chat_events_user_created_index');
            $table->index(['event', 'created_at'], 'chat_events_event_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_events');
        Schema::dropIfExists('client_assistant_notes');
        Schema::dropIfExists('chat_confirmations');
        Schema::dropIfExists('chat_attachments');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
    }
};

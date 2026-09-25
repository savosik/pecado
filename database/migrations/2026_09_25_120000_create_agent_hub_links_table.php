<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_hub_links', function (Blueprint $table) {
            $table->comment('Ссылки-хеши на пульт Agent Hub: доступ к топикам ИИ-агентов без авторизации (админам сайта и админам 1С) и ключ API создания топиков');
            $table->id()->comment('Первичный ключ');
            $table->string('token', 64)->unique()->comment('Секретный хеш — сегмент URL пульта /agent-hub/{token} и ключ API');
            $table->string('label')->comment('Кому выдана ссылка: «Админ 1С», «Наш админ» — для отзыва нужной');
            $table->text('note')->nullable()->comment('Заметка модератора: зачем выдана, кому передана');
            $table->foreignId('created_by')->nullable()->comment('Кто выдал ссылку (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable()->comment('Когда ссылкой пользовались в последний раз (страница или API)');
            $table->timestamp('revoked_at')->nullable()->comment('Когда ссылка отозвана; отозванная даёт 404');
            $table->timestamp('created_at')->nullable()->comment('Дата создания ссылки');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего обновления ссылки');
        });

        Schema::table('agent_topics', function (Blueprint $table) {
            $table->foreignId('hub_link_id')->nullable()->after('created_by')
                ->comment('Ссылка-хеш, по которой создан топик (agent_hub_links.id); пусто — создан из админки')
                ->constrained('agent_hub_links')->nullOnDelete();
            $table->string('created_by_agent', 100)->nullable()->after('hub_link_id')
                ->comment('Имя внешнего агента, создавшего топик по API (agent_name из запроса)');
            $table->string('external_key', 64)->nullable()->after('created_by_agent')
                ->comment('Ключ идемпотентности создания топика от внешнего агента: повторный запрос с тем же ключом вернёт тот же топик');

            $table->unique(['hub_link_id', 'external_key'], 'agent_topics_external_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agent_topics', function (Blueprint $table) {
            $table->dropUnique('agent_topics_external_key_unique');
            $table->dropConstrainedForeignId('hub_link_id');
            $table->dropColumn(['created_by_agent', 'external_key']);
        });

        Schema::dropIfExists('agent_hub_links');
    }
};

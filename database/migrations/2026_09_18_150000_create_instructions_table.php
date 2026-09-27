<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructions', function (Blueprint $table) {
            $table->comment('Инструкции для клиентов, менеджеров (CRM) и склада (WMS): текст блоками, PDF или видео. Файлы — в media (коллекции cover, file, video)');
            $table->id()->comment('Первичный ключ');
            $table->string('title')->comment('Заголовок инструкции');
            $table->text('short_description')->nullable()->comment('Краткое описание — о чём инструкция, показывается в списке');
            $table->string('type', 16)->comment("Формат: 'text' — блоки редактора (content), 'pdf' — файл в media (коллекция file), 'video' — ролик (video_url или коллекция video)");
            $table->longText('content')->nullable()->comment('Текст инструкции в формате блоков Editor.js (JSON), только для type = text');
            $table->string('video_url', 500)->nullable()->comment('Ссылка на ролик (YouTube, Rutube, VK, Vimeo), только для type = video; альтернатива загруженному файлу');
            $table->boolean('for_clients')->default(false)->comment('Показывать клиентам в личном кабинете');
            $table->boolean('for_crm')->default(false)->comment('Показывать менеджерам в CRM');
            $table->boolean('for_wms')->default(false)->comment('Показывать складу в WMS');
            $table->boolean('is_published')->default(true)->comment('Опубликована: скрытая инструкция видна только в админке');
            $table->timestamp('created_at')->nullable()->comment('Дата создания');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего обновления — показывается читателю');

            $table->index(['is_published', 'for_clients']);
            $table->index(['is_published', 'for_crm']);
            $table->index(['is_published', 'for_wms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Таблица была создана в рамках первой (отброшенной) версии URL-кэша.
        // В новом подходе файлы хранятся в MinIO по стабильным путям, таблица не нужна.
        Schema::dropIfExists('product_media_cache');
    }

    public function down(): void
    {
        // Восстановление не предусмотрено — таблица устарела
    }
};

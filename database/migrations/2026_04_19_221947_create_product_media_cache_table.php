<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media_cache', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Артикул товара (product.code), ключ матчинга');
            $table->text('image_main')->nullable()->comment('URL главного изображения с источника');
            $table->json('additional_images')->nullable()->comment('Массив URL дополнительных изображений');
            $table->json('product_videos')->nullable()->comment('Массив URL видео');
            $table->timestamp('cached_at')->nullable()->comment('Когда последний раз обновлён кэш');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media_cache');
    }
};

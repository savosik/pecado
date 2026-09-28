<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_pending_prices', function (Blueprint $table) {
            $table->comment('Базовые цены из price.updated, пришедшие раньше карточки товара: применяются при product.created (v16.12.3)');
            $table->id()->comment('Первичный ключ');
            $table->string('product_uuid', 36)->unique()->comment('UUID товара 1С, карточки которого ещё нет (products.external_id)');
            $table->decimal('price', 15, 2)->comment('Базовая цена из последнего принятого price.updated, руб.');
            $table->string('message_id')->nullable()->comment('message_id последнего price.updated по товару');
            $table->timestamp('created_at')->nullable()->comment('Когда цена впервые отложена');
            $table->timestamp('updated_at')->nullable()->comment('Когда отложенная цена последний раз перезаписана');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_pending_prices');
    }
};

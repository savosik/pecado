<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_scheduled_prices', function (Blueprint $table) {
            $table->comment('Базовые цены из price.updated с будущей датой вступления в силу: включаются планировщиком erp:activate-scheduled-prices (v16.13.0)');
            $table->id()->comment('Первичный ключ');
            $table->string('product_uuid', 36)->comment('UUID товара 1С (products.external_id); карточки может ещё не быть');
            $table->string('document_uuid', 36)->comment('GUID документа 1С «Установка цен номенклатуры»; вместе с product_uuid — ключ отложенной цены');
            $table->string('document_number')->nullable()->comment('Номер документа установки цен — только для разбора');
            $table->decimal('price', 15, 2)->comment('Базовая цена, которая вступит в силу, руб.');
            $table->timestamp('effective_from')->comment('Момент вступления цены в силу (время приложения, МСК)');
            $table->string('message_id')->nullable()->comment('message_id последнего price.updated по паре товар × документ');
            $table->timestamp('created_at')->nullable()->comment('Когда цена впервые отложена');
            $table->timestamp('updated_at')->nullable()->comment('Когда отложенная цена последний раз перезаписана');

            $table->unique(['product_uuid', 'document_uuid']);
            $table->index('effective_from');
            $table->index('document_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_scheduled_prices');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пересоздаём individual_prices с правильной схемой (INT FK вместо UUID CHAR).
 *
 * На dev таблица была создана ранней версией миграции с UUID-полями:
 *   partner_uuid CHAR(36), product_uuid CHAR(36), warehouse_uuid CHAR(36).
 *
 * Правильная схема (v7.1 — INT-based для производительности):
 *   partner_id UNSIGNED INT FK → users.id
 *   product_id UNSIGNED INT FK → products.id
 *   warehouse_id UNSIGNED INT FK → warehouses.id
 *
 * UUID→INT lookup выполняется при импорте в ProcessIndividualPricesFile.
 * Это даёт 7-10x ускорение INSERT и 3-5x ускорение SELECT.
 *
 * Данные в старой таблице не переносятся — они будут пересинхронизированы
 * при следующей загрузке файла из 1С.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('individual_prices');

        Schema::create('individual_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('price', 15, 2);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['partner_id', 'product_id', 'warehouse_id'], 'individual_prices_pk');

            // Индекс для фильтрации по партнёру (основные запросы: WHERE partner_id = ?)
            $table->index('partner_id', 'idx_individual_prices_partner');
            // Индекс для фильтрации по товару
            $table->index('product_id', 'idx_individual_prices_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('individual_prices');

        // Возвращаем старую UUID-схему
        Schema::create('individual_prices', function (Blueprint $table) {
            $table->char('partner_uuid', 36);
            $table->char('product_uuid', 36);
            $table->char('warehouse_uuid', 36);
            $table->decimal('price', 15, 2);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['partner_uuid', 'product_uuid', 'warehouse_uuid']);
            $table->index('partner_uuid', 'idx_individual_prices_partner');
            $table->index('product_uuid', 'idx_individual_prices_product');
        });
    }
};

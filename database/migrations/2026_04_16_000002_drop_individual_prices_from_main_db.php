<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Удаляет таблицу individual_prices из основной БД.
 *
 * Запускается ПОСЛЕ 2026_04_16_000001 — когда таблица уже перенесена
 * в отдельную prices DB (pecado-mysql-prices).
 *
 * Все обращения к individual_prices теперь идут через соединение 'prices'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('individual_prices');
    }

    public function down(): void
    {
        // Восстанавливать таблицу здесь не нужно — при rollback сработает
        // down() предыдущей миграции (recreate_individual_prices_table_with_int_ids)
    }
};

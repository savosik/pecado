<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            // Снимок версии данных каталога на момент успешной генерации
            // (см. App\Services\ProductExport\ProductExportDataVersion).
            // Если глобальная версия в Redis выросла после этого таймштампа —
            // hasFreshCache считает кеш устаревшим и перегенерирует.
            $table->timestamp('data_version_at')->nullable()->after('cached_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            $table->dropColumn('data_version_at');
        });
    }
};

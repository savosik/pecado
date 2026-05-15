<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            // Оценочное число строк в выгрузке. Используется
            // GenerateProductExportJob-ом для выбора очереди:
            //   < EXPORTS_HEAVY_THRESHOLD → exports-light (быстрый pool);
            //   ≥ — exports-heavy (длинный pool).
            // Обновляется в Model::saving event при изменении filters и в
            // ProductExportGenerator после успешной генерации.
            $table->unsignedInteger('estimated_rows')->nullable()->after('data_version_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            $table->dropColumn('estimated_rows');
        });
    }
};

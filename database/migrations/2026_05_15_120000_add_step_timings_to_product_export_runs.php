<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_export_runs', function (Blueprint $table) {
            // Сколько миллисекунд джоба провисела в очереди до старта генерации.
            // Помогает увидеть, упирается ли отдача в backlog воркеров, а не в генерацию.
            $table->unsignedInteger('queued_for_ms')->nullable()->after('duration_ms');

            // Разбивка длительности по этапам: ключ → миллисекунды.
            // Структура: {"query": 120, "eager_load": 4800, "price_map": 1200, "stock_map": 800,
            //             "map_rows": 9500, "write_format": 3100, "other": 200}
            // Заполняется StepTimer в ProductExportGenerator и пресетах.
            $table->json('steps_json')->nullable()->after('queued_for_ms');
        });
    }

    public function down(): void
    {
        Schema::table('product_export_runs', function (Blueprint $table) {
            $table->dropColumn(['queued_for_ms', 'steps_json']);
        });
    }
};

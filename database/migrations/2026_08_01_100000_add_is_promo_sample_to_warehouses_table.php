<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Признак склада рекламных образцов («Москва реклама»).
 *
 * Заказы `promo_sample` уходят в 1С с warehouse_uuids этого склада. Завязываемся
 * на флаг, а не на UUID в конфиге, — ровно как это сделано для склада некондиции
 * (`2026_07_20_100100_add_is_defect_to_warehouses_table.php`): на момент миграции
 * external_id склада ещё не получен от 1С, и гейт в `PublishOrderToErp` держит
 * такие заказы до его появления.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('is_promo_sample')->default(false)->after('is_defect')
                ->comment('Склад рекламных образцов: с него отгружаются пробники (orders.type = promo_sample)');
        });

        // Склад может быть заведён вручную до появления фичи — помечаем по имени.
        DB::table('warehouses')->where('name', 'Москва реклама')->update(['is_promo_sample' => true]);
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('is_promo_sample');
        });
    }
};

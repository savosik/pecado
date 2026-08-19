<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Режим «стопки складов»: приоритетная последовательность primary-складов региона.
     * Верхний склад замещает собой остатки и цены нижних по позициям, которые на нём
     * в наличии; нижние — фолбэк. Флаг выключен / priority NULL — прежнее поведение
     * (суммирование остатков по складам региона).
     */
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->boolean('stock_stack_enabled')
                ->default(false)
                ->after('currency_id')
                ->comment('Режим стопки складов: остатки и цены по приоритету складов (строгое замещение) вместо суммирования');
        });

        Schema::table('region_warehouse', function (Blueprint $table) {
            $table->unsignedSmallInteger('priority')
                ->nullable()
                ->after('type')
                ->comment('Позиция склада в стопке региона: 1 — верхний (замещает нижние). NULL — приоритет не задан');
            $table->unique(['region_id', 'type', 'priority'], 'region_warehouse_stack_position_unique');
        });
    }

    public function down(): void
    {
        Schema::table('region_warehouse', function (Blueprint $table) {
            $table->dropUnique('region_warehouse_stack_position_unique');
            $table->dropColumn('priority');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('stock_stack_enabled');
        });
    }
};

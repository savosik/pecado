<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v12.3: Унификация нейминга ИНН контрагента.
 * Переименование contractor_inn → tax_id в таблицах contractor_balances и shipments.
 * Приводит нейминг протокола обмена и БД к единому стандарту (tax_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractor_balances', function (Blueprint $table) {
            $table->renameColumn('contractor_inn', 'tax_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->renameColumn('contractor_inn', 'tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('contractor_balances', function (Blueprint $table) {
            $table->renameColumn('tax_id', 'contractor_inn');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->renameColumn('tax_id', 'contractor_inn');
        });
    }
};

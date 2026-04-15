<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Номер заказа/возврата из 1С.
     * Отображается пользователю для коммуникации с менеджером.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('erp_number')->nullable()->after('number')
                ->comment('Номер заказа в 1С');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->string('erp_number')->nullable()->after('uuid')
                ->comment('Номер возврата в 1С');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('erp_number');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('erp_number');
        });
    }
};

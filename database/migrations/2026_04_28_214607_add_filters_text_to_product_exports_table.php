<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            $table->text('filters_text')->nullable()->after('filters')
                ->comment('Денормализованные имена брендов/категорий/складов/сертификатов из filters JSON для LIKE-поиска');
        });
    }

    public function down(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            $table->dropColumn('filters_text');
        });
    }
};

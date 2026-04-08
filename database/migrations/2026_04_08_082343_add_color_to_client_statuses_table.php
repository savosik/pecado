<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_statuses', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('name')->comment('HEX цвет статуса, напр. #FFD700');
        });
    }

    public function down(): void
    {
        Schema::table('client_statuses', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};

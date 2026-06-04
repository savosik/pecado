<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_managers', function (Blueprint $table) {
            $table->string('erp_uuid', 36)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_managers', function (Blueprint $table) {
            $table->dropUnique(['erp_uuid']);
            $table->dropColumn('erp_uuid');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('personal_manager_id')
                ->nullable()
                ->after('client_status_id')
                ->constrained('personal_managers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['personal_manager_id']);
            $table->dropColumn('personal_manager_id');
        });
    }
};

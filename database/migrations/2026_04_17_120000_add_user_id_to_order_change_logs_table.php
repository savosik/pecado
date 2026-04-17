<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_change_logs', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('source')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_change_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

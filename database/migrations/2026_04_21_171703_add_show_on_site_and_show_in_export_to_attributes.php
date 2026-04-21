<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->boolean('show_on_site')->default(true)->after('is_active');
            $table->boolean('show_in_export')->default(true)->after('show_on_site');
            $table->index('show_on_site');
            $table->index('show_in_export');
        });
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropIndex(['show_on_site']);
            $table->dropIndex(['show_in_export']);
            $table->dropColumn(['show_on_site', 'show_in_export']);
        });
    }
};

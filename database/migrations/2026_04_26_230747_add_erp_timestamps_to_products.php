<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('erp_created_at')->nullable()->after('updated_at');
            $table->timestamp('erp_updated_at')->nullable()->after('erp_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['erp_created_at', 'erp_updated_at']);
        });
    }
};

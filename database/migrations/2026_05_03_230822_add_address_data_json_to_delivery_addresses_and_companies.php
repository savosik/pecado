<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->json('address_data')->nullable()->after('address');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->json('legal_address_data')->nullable()->after('legal_address');
            $table->json('actual_address_data')->nullable()->after('actual_address');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->dropColumn('address_data');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['legal_address_data', 'actual_address_data']);
        });
    }
};

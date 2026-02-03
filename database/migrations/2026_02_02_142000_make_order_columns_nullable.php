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
        Schema::table('orders', function (Blueprint $table) {
            // Drop the foreign key and column
            $table->dropForeign(['delivery_address_id']);
            $table->dropColumn('delivery_address_id');
            
            // Add text field for delivery address
            $table->text('delivery_address')->nullable()->after('comment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_address');
            $table->foreignId('delivery_address_id')->nullable()->constrained()->onDelete('cascade');
        });
    }
};

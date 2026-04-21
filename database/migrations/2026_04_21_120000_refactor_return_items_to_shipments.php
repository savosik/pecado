<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('return_items')->delete();
        DB::table('returns')->delete();

        Schema::table('return_items', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->foreignId('shipment_item_id')
                ->after('return_id')
                ->constrained('shipment_items')
                ->restrictOnDelete();
            $table->foreignId('shipment_id')
                ->after('shipment_item_id')
                ->constrained('shipments')
                ->restrictOnDelete();
            $table->index('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropIndex(['shipment_id']);
            $table->dropForeign(['shipment_id']);
            $table->dropColumn('shipment_id');
            $table->dropForeign(['shipment_item_id']);
            $table->dropColumn('shipment_item_id');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('return_id')->constrained()->cascadeOnDelete();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weight_gross', 10, 3)->nullable()->after('tnved');
            $table->decimal('weight_net', 10, 3)->nullable()->after('weight_gross');
            $table->decimal('width', 10, 2)->nullable()->after('weight_net');
            $table->decimal('height', 10, 2)->nullable()->after('width');
            $table->decimal('depth', 10, 2)->nullable()->after('height');
            $table->string('hs_code', 20)->nullable()->after('depth');
            $table->string('abc_xyz', 5)->nullable()->after('hs_code');
            $table->decimal('turnover', 12, 4)->nullable()->after('abc_xyz');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'weight_gross',
                'weight_net',
                'width',
                'height',
                'depth',
                'hs_code',
                'abc_xyz',
                'turnover',
            ]);
        });
    }
};

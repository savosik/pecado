<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('default_delivery_method')
                ->nullable()
                ->after('city')
                ->comment("Последний выбранный способ доставки: 'delivery' — доставка, 'pickup' — самовывоз");
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_delivery_method');
        });
    }
};

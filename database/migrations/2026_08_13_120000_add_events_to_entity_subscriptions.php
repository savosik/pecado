<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_subscriptions', function (Blueprint $table) {
            $table->json('events')->nullable()->after('destination')
                ->comment("Типы событий раздела, на которые подписан адресат — JSON-массив ключей из config/subscriptions.php (для 'orders': items_updated, attributes_updated, api_shortfall). NULL — все типы, включая те, что появятся позже");
        });
    }

    public function down(): void
    {
        Schema::table('entity_subscriptions', function (Blueprint $table) {
            $table->dropColumn('events');
        });
    }
};

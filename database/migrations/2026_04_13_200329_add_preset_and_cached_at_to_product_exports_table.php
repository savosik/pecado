<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            $table->string('preset', 50)->nullable()->after('format')
                ->comment('Preset type key (yml, shopify, woocommerce, etc.) — null for custom exports');
            $table->timestamp('cached_at')->nullable()->after('last_downloaded_at')
                ->comment('When the cached export file was last generated');
        });
    }

    public function down(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            $table->dropColumn(['preset', 'cached_at']);
        });
    }
};

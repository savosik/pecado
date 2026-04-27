<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('rich_content_generated_at')->nullable()->after('rich_content');
            $table->timestamp('rich_content_generation_failed_at')->nullable()->after('rich_content_generated_at');
            $table->unsignedSmallInteger('rich_content_generation_attempts')->default(0)->after('rich_content_generation_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'rich_content_generated_at',
                'rich_content_generation_failed_at',
                'rich_content_generation_attempts',
            ]);
        });
    }
};

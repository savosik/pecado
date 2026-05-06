<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_export_id')
                ->constrained('product_exports')
                ->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('rows_count')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();

            $table->index(['product_export_id', 'created_at']);
            $table->index('status');
        });

        Schema::table('product_exports', function (Blueprint $table) {
            $table->string('status', 20)->default('idle')->after('cached_at');
            $table->foreignId('last_run_id')
                ->nullable()
                ->after('status')
                ->constrained('product_export_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_exports', function (Blueprint $table) {
            $table->dropForeign(['last_run_id']);
            $table->dropColumn(['last_run_id', 'status']);
        });

        Schema::dropIfExists('product_export_runs');
    }
};

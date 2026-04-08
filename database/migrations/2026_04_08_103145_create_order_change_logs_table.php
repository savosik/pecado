<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);            // 'items_updated', 'status_changed'
            $table->text('summary');                // Человекочитаемое описание на русском
            $table->json('changes');                // Структурированный diff
            $table->string('source', 20)->default('erp'); // 'erp', 'admin', 'system'
            $table->decimal('old_total', 12, 2)->nullable();
            $table->decimal('new_total', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_change_logs');
    }
};

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
        Schema::create('erp_bus_messages', function (Blueprint $table) {
            $table->id();
            $table->enum('direction', ['incoming', 'outgoing']);
            $table->string('routing_key')->nullable()->comment('Очередь / routing key');
            $table->string('event')->comment('Тип события (partner.created, order.updated, ...)');
            $table->string('message_id')->nullable()->comment('message_id из payload');
            $table->json('payload')->comment('Полный JSON payload');
            $table->enum('status', ['success', 'failed'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Индексы для быстрой фильтрации
            $table->index('direction');
            $table->index('event');
            $table->index('status');
            $table->index('created_at');
            $table->index('message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('erp_bus_messages');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_validation_errors', function (Blueprint $table) {
            $table->id();
            $table->string('event', 100)->index();
            $table->string('direction', 10)->default('incoming'); // incoming | outgoing
            $table->string('message_id', 255)->nullable();
            $table->json('errors');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_validation_errors');
    }
};

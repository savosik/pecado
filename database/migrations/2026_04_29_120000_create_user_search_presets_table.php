<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_search_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('section', 32);
            $table->string('name', 120);
            $table->json('filters');
            $table->timestamps();

            $table->index(['user_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_search_presets');
    }
};

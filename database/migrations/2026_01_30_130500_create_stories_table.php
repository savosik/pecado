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
        // Сторис - набор слайдов (группа)
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_published')->default(false);
            $table->boolean('show_name')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Слайды внутри сториса
        Schema::create('story_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable(); // Заголовок слайда
            $table->text('content')->nullable(); // Текстовый контент слайда
            $table->string('button_text')->nullable(); // Текст кнопки
            $table->string('button_url')->nullable(); // URL кнопки
            $table->string('linkable_type')->nullable(); // Полиморфная связь - тип сущности
            $table->unsignedBigInteger('linkable_id')->nullable(); // Полиморфная связь - ID сущности
            $table->integer('duration')->default(5); // Длительность показа слайда в секундах
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_slides');
        Schema::dropIfExists('stories');
    }
};

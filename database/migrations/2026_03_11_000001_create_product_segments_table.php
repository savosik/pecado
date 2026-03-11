<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * US-11: Сегменты номенклатуры.
     * US-12: Сегменты партнёров.
     * US-03 v2: Связи скидок с сегментами.
     * Хранит сегменты из 1С и связи many-to-many с товарами/пользователями/скидками.
     */
    public function up(): void
    {
        // US-11: Сегменты номенклатуры
        Schema::create('product_segments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('UUID из 1С');
            $table->string('name')->comment('Наименование сегмента');
            $table->timestamps();
        });

        Schema::create('product_product_segment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_segment_id')->constrained('product_segments')->cascadeOnDelete();
            $table->unique(['product_id', 'product_segment_id']);
        });

        // US-12: Сегменты партнёров
        Schema::create('partner_segments', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique()->comment('UUID из 1С');
            $table->string('name')->comment('Наименование сегмента партнёров');
            $table->timestamps();
        });

        Schema::create('partner_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_segment_id')->constrained('partner_segments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['partner_segment_id', 'user_id']);
        });

        // US-03 v2: Привязка скидок к сегментам номенклатуры
        Schema::create('discount_product_segment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_segment_id')->constrained('product_segments')->cascadeOnDelete();
            $table->unique(['discount_id', 'product_segment_id']);
        });

        // US-03 v2: Привязка скидок к сегментам партнёров
        Schema::create('discount_partner_segment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_segment_id')->constrained('partner_segments')->cascadeOnDelete();
            $table->unique(['discount_id', 'partner_segment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_partner_segment');
        Schema::dropIfExists('discount_product_segment');
        Schema::dropIfExists('partner_user');
        Schema::dropIfExists('partner_segments');
        Schema::dropIfExists('product_product_segment');
        Schema::dropIfExists('product_segments');
    }
};

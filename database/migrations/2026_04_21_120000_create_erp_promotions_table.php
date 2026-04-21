<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_promotions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('type', ['new', 'bestseller', 'liquidation'])->index();
            $table->timestamps();
        });

        Schema::create('erp_promotion_product', function (Blueprint $table) {
            $table->foreignId('erp_promotion_id')->constrained('erp_promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['erp_promotion_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_promotion_product');
        Schema::dropIfExists('erp_promotions');
    }
};

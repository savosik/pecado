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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 10, 2)->nullable();
            $table->enum('item_type', ['instock', 'preorder'])->default('instock');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['cart_id', 'product_id', 'item_type', 'warehouse_id'], 'cart_items_unique');
            $table->index(['cart_id', 'item_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};

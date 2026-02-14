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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('base_price', 15, 2);
            $table->uuid('external_id')->nullable()->unique();
            $table->boolean('is_new')->default(false);
            $table->boolean('is_bestseller')->default(false);

            // New fields from XML feed
            $table->string('code')->nullable();
            $table->string('sku')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->string('url')->nullable();
            $table->string('barcode')->nullable();
            $table->string('tnved')->nullable();

            // Flags
            $table->boolean('is_marked')->default(false);
            $table->boolean('is_liquidation')->default(false);
            $table->boolean('for_marketplaces')->default(false);

            // Descriptions
            $table->text('description')->nullable();
            $table->text('description_html')->nullable();
            $table->text('short_description')->nullable();

            // SEO Meta
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            // Foreign keys (actual constraints added later due to migration order)
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->unsignedBigInteger('size_chart_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

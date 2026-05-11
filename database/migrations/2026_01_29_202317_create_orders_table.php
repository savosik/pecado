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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('delivery_address_id')->nullable()->constrained('delivery_addresses')->nullOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->onDelete('set null');
            $table->string('status')->default(\App\Enums\OrderStatus::PENDING_APPROVAL->value);
            $table->text('comment')->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->decimal('rate_coefficient', 10, 4)->default(1);
            $table->string('currency_code')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('orders')->onDelete('cascade');
            $table->string('type')->default(\App\Enums\OrderType::ORDER->value);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('hash', 64)->unique();
            $table->string('format', 10)->default('json');
            $table->json('filters')->nullable();
            $table->json('fields');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();

            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_exports');
    }
};

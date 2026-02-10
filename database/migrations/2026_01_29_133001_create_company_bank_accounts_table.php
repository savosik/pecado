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
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('bank_bik')->nullable(); // БИК банка
            $table->string('correspondent_account')->nullable(); // Корреспондентский счёт
            $table->string('account_number'); // Номер расчётного счёта
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
    }
};

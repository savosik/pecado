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
        Schema::create('contractor_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contractor_uuid')->nullable()->comment('UUID контрагента из 1С');
            $table->string('contractor_inn')->comment('ИНН контрагента');
            $table->decimal('current_balance', 20, 2)->default(0)->comment('Текущий баланс (RUB)');
            $table->decimal('overdue_debt', 20, 2)->default(0)->comment('Просроченная задолженность (RUB)');
            $table->timestamp('balance_erp_updated_at')->nullable()->comment('Дата обновления баланса из 1С');
            $table->timestamps();
            $table->unique(['user_id', 'contractor_inn']);
        });

        Schema::create('contractor_balance_overdue_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_balance_id')
                ->constrained('contractor_balances')
                ->cascadeOnDelete();
            $table->string('shipment_uuid')->comment('UUID реализации из 1С');
            $table->decimal('amount', 20, 2)->comment('Сумма просрочки');
            $table->date('due_date')->comment('Дата оплаты');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractor_balance_overdue_details');
        Schema::dropIfExists('contractor_balances');
    }
};

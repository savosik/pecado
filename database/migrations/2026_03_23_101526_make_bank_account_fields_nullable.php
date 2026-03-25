<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->change();
            $table->string('bank_bik')->nullable()->change();
            $table->string('correspondent_account')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->string('bank_name')->nullable(false)->change();
            $table->string('bank_bik')->nullable(false)->change();
            $table->string('correspondent_account')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расширяем колонку phone с varchar(20) до varchar(255).
 *
 * 1С присылает в поле phone длинные строки с несколькими номерами,
 * факсами и комментариями (например: "+7 (4232) 41-47-36, 914-706-10-20 Арсен").
 * varchar(20) вызывает SQLSTATE[22001] Data too long и приводит партнёров в DLQ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
        });
    }
};

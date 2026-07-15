<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_managers', function (Blueprint $table) {
            // ->constrained() возвращает определение ключа, а не столбца, поэтому
            // ->comment() в одной цепочке с ним потерялся бы: объявляем раздельно.
            $table->foreignId('user_id')
                ->nullable()
                ->after('erp_uuid')
                ->comment('Аккаунт сотрудника в CRM (users.id). NULL — у менеджера ещё нет входа на сайт');

            $table->unique('user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_managers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

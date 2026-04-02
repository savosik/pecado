<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Объединяем surname + name + patronymic → name
        DB::statement("
            UPDATE users
            SET name = TRIM(CONCAT_WS(' ',
                NULLIF(surname, ''),
                NULLIF(name, ''),
                NULLIF(patronymic, '')
            ))
            WHERE surname IS NOT NULL AND surname != ''
               OR patronymic IS NOT NULL AND patronymic != ''
        ");

        // 2. Удаляем столбцы surname и patronymic
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['surname', 'patronymic']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('surname')->nullable()->after('name');
            $table->string('patronymic')->nullable()->after('surname');
        });
    }
};

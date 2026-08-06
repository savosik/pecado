<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Рабочее наименование партнёра — то, под которым клиент заведён в 1С.
 *
 * До этой миграции `partner.updated` перезаписывал `users.name`, и правка имени
 * в личном кабинете жила до следующего сообщения из 1С. Менеджеры сличают отчёты
 * сайта и 1С именно по наименованию, поэтому источники разводятся: `name` —
 * то, как клиент назвал себя сам, `erp_name` — наименование карточки в 1С.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('erp_name')
                ->nullable()
                ->after('erp_id')
                ->comment('Рабочее наименование партнёра из 1С (мастер — 1С, на сайте только для чтения)');

            // Индекс под сортировку списка клиентов CRM по наименованию.
            $table->index('erp_name');
        });

        // Бэкфилл: у партнёров с erp_id текущее name и есть наименование из 1С —
        // до сегодняшнего дня оно приезжало именно в эту колонку.
        DB::table('users')
            ->whereNotNull('erp_id')
            ->whereNull('erp_name')
            ->update(['erp_name' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['erp_name']);
            $table->dropColumn('erp_name');
        });
    }
};

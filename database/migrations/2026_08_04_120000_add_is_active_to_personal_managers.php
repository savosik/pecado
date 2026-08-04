<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг «карточка менеджера работает».
 *
 * 1С заводит карточку каждому, кого хоть раз указали менеджером в документе,
 * поэтому в справочнике живут уволившиеся, дубли и технические записи. Удалять
 * их нельзя: следующий обмен создаст карточку заново по erp_uuid, а клиенты
 * потеряют привязку. Флаг убирает мусор из выборов и сеток CRM, оставляя
 * саму карточку и историю нетронутыми.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_managers', function (Blueprint $table) {
            $table->boolean('is_active')
                ->default(true)
                ->after('email')
                ->comment('Карточка работает: false — скрыта из списков и выборов CRM (уволился, дубль, техническая запись)');
        });
    }

    public function down(): void
    {
        Schema::table('personal_managers', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};

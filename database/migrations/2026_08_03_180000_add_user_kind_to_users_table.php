<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тип аккаунта пользователя: клиент, сотрудник или служебная учётка.
 *
 * Клиентом до сих пор считался любой пользователь с непустым personal_manager_id,
 * а менеджера проставляет 1С всем партнёрам подряд — типа партнёра в payload
 * `partner.created` нет. Из-за этого в /crm/clients висели закупщики, админы
 * и технические учётки: им ставили планы и считали их в покрытии задачами.
 *
 * Колонка в users, а не в crm_client_profiles, хотя CRM-поля живут отдельно:
 * это не свойство работы с клиентом, а классификация самой учётной записи —
 * профиля у сотрудника нет и не будет, а отделять его нужно уже на выборке.
 * Границе владения это не противоречит: обработчики 1С пишут фиксированный
 * список колонок, user_kind в него не входит и переживает partner.updated.
 *
 * Дефолт 'client' — иначе все существующие строки стали бы «непонятно кем»,
 * и CRM опустела бы до ручной разметки. Обратная логика («по умолчанию не
 * клиент») безопаснее звучит, но ломает рабочий раздел на время разметки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_kind', 20)
                ->default('client')
                ->after('status')
                ->comment("Тип аккаунта: 'client' — клиент, 'staff' — сотрудник компании, 'service' — служебная/техническая учётка");

            // Индекс под выборку сотрудников и служебных: клиентов ~99%,
            // и для них планировщик всё равно пойдёт по personal_manager_id.
            $table->index('user_kind', 'users_user_kind_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_user_kind_index');
            $table->dropColumn('user_kind');
        });
    }
};

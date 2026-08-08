<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каналы клиента как независимые признаки, а не как один «основной канал».
 *
 * `primary_channel`/`secondary_channel` отвечают на вопрос «откуда у клиента
 * оборот» и допускают максимум два значения из трёх. Отдел же ведёт учёт иначе:
 * офлайн-точки, собственный интернет-магазин и торговля на маркетплейсах — три
 * независимых «да/нет», и они регулярно стоят все сразу. Отсюда отдельные флаги:
 * ими отбирают клиентов под конкретное предложение (например, витринные
 * материалы — только тем, у кого есть офлайн-точки).
 *
 * Тип nullable, а не `boolean default false`: незаполненный профиль обязан
 * отличаться от «выяснили, точек нет» — иначе весь отдел разом получил бы
 * ложные «нет» по клиентам, о которых никто ничего не спрашивал.
 *
 * Заодно расширены перечисления в комментариях `business_type` и
 * `lifecycle_status` — в них добавились «Селлер»/«Массмаркет» и
 * «Закрывается»/«Непреодолимо».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            $table->boolean('has_offline_points')->nullable()->after('points_count')
                ->comment('Есть офлайн-точки; NULL — не выяснено');
            $table->boolean('has_online_store')->nullable()->after('has_offline_points')
                ->comment('Есть собственный интернет-магазин; NULL — не выяснено');
            $table->boolean('works_with_marketplaces')->nullable()->after('has_online_store')
                ->comment('Торгует на маркетплейсах; NULL — не выяснено');

            $table->string('business_type', 20)->nullable()
                ->comment("Вид бизнеса: 'offline' — офлайн-розница, 'online' — интернет-магазин, 'chain' — сеть, 'wholesale' — опт, 'seller' — селлер на маркетплейсах, 'mass_market' — федеральный массмаркет")
                ->change();

            $table->string('lifecycle_status', 20)->default('active')
                ->comment("Стадия работы: 'lead' — лид, 'in_work' — в работе, 'active' — активен, 'sleeping' — спящий, 'closing' — закрывается, 'churned' — ушёл, 'hopeless' — непреодолимо")
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'has_offline_points',
                'has_online_store',
                'works_with_marketplaces',
            ]);

            $table->string('business_type', 20)->nullable()
                ->comment("Вид бизнеса: 'offline' — офлайн-розница, 'online' — интернет-магазин, 'chain' — сеть, 'wholesale' — опт")
                ->change();

            $table->string('lifecycle_status', 20)->default('active')
                ->comment("Стадия работы с клиентом: 'lead' — лид, 'in_work' — в работе, 'active' — активен, 'sleeping' — спящий, 'churned' — ушёл")
                ->change();
        });
    }
};

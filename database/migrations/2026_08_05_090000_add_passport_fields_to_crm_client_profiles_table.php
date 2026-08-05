<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Паспорт клиента: то, что менеджер знает о бизнесе клиента, а 1С — нет.
 *
 * Раньше всё это жило в свободных заметках. Текст читается человеком, но по нему
 * нельзя ни отобрать клиентов («сети премиум-сегмента с отсрочкой»), ни отсечь
 * товар на подборе — а именно ради этого отдел данные и собирает: предложить
 * клиенту категорию, которую он принципиально не берёт, дороже, чем не предложить
 * ничего. Поэтому опорные атрибуты становятся колонками, а заметки остаются для
 * всего, что в перечень не укладывается.
 *
 * Колонки nullable: профиль заполняется разговором с менеджером и месяцами живёт
 * наполовину пустым — «не выяснено» здесь нормальное состояние, а не дефект данных.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            // --- Бизнес клиента ---
            $table->string('business_type', 20)->nullable()->after('user_id')
                ->comment("Вид бизнеса: 'offline' — офлайн-розница, 'online' — интернет-магазин, 'chain' — сеть, 'wholesale' — опт");
            $table->unsignedSmallInteger('points_count')->nullable()->after('business_type')
                ->comment('Количество торговых точек клиента');
            $table->string('specialization', 20)->nullable()->after('points_count')
                ->comment("Специализация матрицы: 'adult' — секач, 'lingerie' — бельё, 'cosmetics' — косметика и уход, 'mixed' — смешанная");
            $table->string('primary_channel', 20)->nullable()->after('specialization')
                ->comment("Основной канал оборота: 'offline', 'online', 'marketplace'");
            $table->string('secondary_channel', 20)->nullable()->after('primary_channel')
                ->comment("Вторичный канал — по нему допродажи не форсируем: 'offline', 'online', 'marketplace'");
            $table->string('point_location', 20)->nullable()->after('secondary_channel')
                ->comment("Локация точек: 'transport_hub', 'residential', 'mall', 'street_retail', 'industrial'");
            $table->string('price_segment', 20)->nullable()->after('point_location')
                ->comment("Ценовой сегмент: 'economy', 'medium', 'premium'");
            $table->string('staff_level', 20)->nullable()->after('price_segment')
                ->comment("Уровень продавцов на точках: 'experts', 'novices', 'mixed'");

            // --- География и логистика ---
            $table->string('regions')->nullable()->after('staff_level')
                ->comment('Регионы присутствия, если отличаются от юридического адреса');
            $table->string('delivery_method', 20)->nullable()->after('regions')
                ->comment("Способ доставки: 'pickup' — самовывоз, 'we_deliver' — везём мы, 'carrier' — ТК, 'mixed'");
            $table->string('carrier')->nullable()->after('delivery_method')
                ->comment('Транспортная компания и терминал или адрес доставки');
            $table->string('receiving_hours')->nullable()->after('carrier')
                ->comment('График приёмки: дни и часы, когда клиент принимает товар');
            $table->text('packaging_notes')->nullable()->after('receiving_hours')
                ->comment('Особенности упаковки и маркировки для этого клиента');

            // --- Коммерческие и финансовые условия ---
            $table->string('payment_type', 20)->nullable()->after('packaging_notes')
                ->comment("Договорный тип оплаты: 'prepay' — предоплата, 'deferred' — отсрочка, 'mixed'. Не путать с payment_behavior — наблюдением менеджера");
            $table->unsignedSmallInteger('deferral_days')->nullable()->after('payment_type')
                ->comment('Срок отсрочки в днях');
            $table->string('credit_rating', 20)->nullable()->after('deferral_days')
                ->comment("Опыт оплаты: 'reliable' — платит вовремя, 'disciplined', 'problematic' — задерживает, 'risky'");
            $table->text('commercial_terms')->nullable()->after('credit_rating')
                ->comment('Дополнительные коммерческие условия: бесплатная доставка, компенсация логистики, спецскидка');
            $table->text('unique_terms')->nullable()->after('commercial_terms')
                ->comment('Уникальные индивидуальные договорённости с датой, с которой действуют');

            // --- Ограничения: то, что нельзя предлагать ---
            $table->text('taboo_categories')->nullable()->after('unique_terms')
                ->comment('Категории, которые клиент принципиально не берёт — исключаются из допродаж и акций');
            $table->text('taboo_brands')->nullable()->after('taboo_categories')
                ->comment('Бренды, с которыми клиент не работает или берёт у эксклюзивных поставщиков');

            // --- Конкурентная среда ---
            $table->text('competitors')->nullable()->after('taboo_brands')
                ->comment('Альтернативные поставщики и бренды, которые клиент закупает параллельно');

            // --- Контакты по ролям (ЛПР по закупкам живёт в decision_maker_*) ---
            $table->date('decision_maker_birthday')->nullable()->after('decision_maker_contact')
                ->comment('День рождения ЛПР по закупкам');
            $table->string('accountant_name')->nullable()->after('competitors')
                ->comment('Бухгалтер клиента — контакт по дебиторке: ФИО');
            $table->string('accountant_contact')->nullable()->after('accountant_name')
                ->comment('Бухгалтер: телефон и почта');
            $table->string('owner_name')->nullable()->after('accountant_contact')
                ->comment('Собственник — контакт для экстренной дебиторки и крупных сделок: ФИО');
            $table->string('owner_contact')->nullable()->after('owner_name')
                ->comment('Собственник: телефон и почта');

            // --- Как общаться ---
            $table->string('novelty_attitude', 20)->nullable()->after('owner_contact')
                ->comment("Отношение к новинкам: 'conservative' — только проверенное, 'innovator' — готов тестировать");
            $table->string('psychotype', 20)->nullable()->after('novelty_attitude')
                ->comment("Стиль общения: 'brief', 'talkative', 'discount_hunter', 'tough'");

            // --- Маркетинговый потенциал ---
            $table->text('marketing_needs')->nullable()->after('psychotype')
                ->comment('Потребности в поддержке: тестеры, POS-материалы, обучение персонала клиента');
            $table->text('traffic_work')->nullable()->after('marketing_needs')
                ->comment('Работа с трафиком: блогеры, инфлюенсеры, реклама');
        });

        // Индексы на то, по чему реально отбирают клиентов для обзвона.
        // Остальные колонки читаются в карточке и фильтром не служат.
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            $table->index('business_type', 'crm_client_profiles_business_type_idx');
            $table->index('price_segment', 'crm_client_profiles_price_segment_idx');
            $table->index('credit_rating', 'crm_client_profiles_credit_rating_idx');
            $table->index('specialization', 'crm_client_profiles_specialization_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            $table->dropIndex('crm_client_profiles_business_type_idx');
            $table->dropIndex('crm_client_profiles_price_segment_idx');
            $table->dropIndex('crm_client_profiles_credit_rating_idx');
            $table->dropIndex('crm_client_profiles_specialization_idx');

            $table->dropColumn([
                'business_type', 'points_count', 'specialization', 'primary_channel',
                'secondary_channel', 'point_location', 'price_segment', 'staff_level',
                'regions', 'delivery_method', 'carrier', 'receiving_hours', 'packaging_notes',
                'payment_type', 'deferral_days', 'credit_rating', 'commercial_terms', 'unique_terms',
                'taboo_categories', 'taboo_brands', 'competitors',
                'decision_maker_birthday', 'accountant_name', 'accountant_contact',
                'owner_name', 'owner_contact',
                'novelty_attitude', 'psychotype', 'marketing_needs', 'traffic_work',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Профиль клиента в CRM: то, что знает менеджер, но не знает 1С.
 *
 * Отдельная таблица, а не колонки в `users`, потому что `users` — зона владения 1С:
 * HandlePartnerCreated/HandlePartnerUpdated собирают $updateData и пишут туда при каждом
 * сообщении о партнёре. Сегодня новые колонки в users не утекли бы в шину (PublishUserToErp
 * отправляет партнёра только при первом заполнении name), но граница владения должна быть
 * явной: следующая правка обработчика не должна иметь шанса затереть заметки менеджера.
 *
 * Enum-поля — строки, а не MySQL enum: набор значений будет уточняться в работе,
 * а ALTER TABLE на боевой таблице ради нового варианта «настроения» того не стоит.
 * Значения валидируются PHP-енумами из App\Enums\Crm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_client_profiles', function (Blueprint $table) {
            $table->comment('Профиль клиента в CRM: то, что знает менеджер, но не знает 1С');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('user_id')
                ->unique()
                ->comment('Клиент, к которому относится профиль (users.id), один к одному')
                ->constrained('users')
                ->cascadeOnDelete();

            // Кто принимает решения
            $table->string('decision_maker_name')->nullable()
                ->comment('ЛПР: имя');
            $table->string('decision_maker_role')->nullable()
                ->comment('ЛПР: должность или роль в закупке');
            $table->string('decision_maker_contact')->nullable()
                ->comment('ЛПР: телефон, почта, мессенджер');
            $table->text('decision_process')->nullable()
                ->comment('Как принимается решение: кто согласует, сколько ждать');

            // Как платит
            $table->string('payment_behavior', 30)->nullable()
                ->comment("Платёжное поведение: 'prepay' — предоплата, 'on_delivery' — по факту, 'deferred' — отсрочка, 'mixed' — по-разному, 'problematic' — задерживает");
            $table->string('payment_terms')->nullable()
                ->comment('Условия словами: «отсрочка 14 дней», «только по счёту»');
            $table->unsignedSmallInteger('order_cycle_days')->nullable()
                ->comment('Обычная периодичность закупок в днях — для напоминаний');

            // Как общаться
            $table->string('preferred_channel', 20)->nullable()
                ->comment("Канал связи: 'phone' — телефон, 'email' — почта, 'whatsapp' — WhatsApp, 'telegram' — Telegram, 'personal' — личные встречи");
            $table->string('sentiment', 20)->nullable()
                ->comment("Настроение: 'loyal' — лоялен, 'neutral' — нейтрален, 'irritated' — раздражён, 'at_risk' — на грани ухода");

            // Свободная часть
            $table->longText('notes_md')->nullable()
                ->comment('Заметки о клиенте в Markdown: всё, что не влезло в поля');
            $table->timestamp('notes_updated_at')->nullable()
                ->comment('Когда заметки правились в последний раз');
            $table->foreignId('notes_updated_by')->nullable()
                ->comment('Кто правил заметки последним (users.id)')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда профиль заведён');
            $table->timestamp('updated_at')->nullable()->comment('Когда профиль последний раз менялся');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_client_profiles');
    }
};

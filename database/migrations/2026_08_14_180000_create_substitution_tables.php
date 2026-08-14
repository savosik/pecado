<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Домен замен недоборов (sub-01).
 *
 * Четыре таблицы: подборка замен по заказу, её строки-кандидаты, справочник
 * связей «этот товар вместо того» и плоский лог сигналов для обучения слоёв.
 *
 * Ключевое решение схемы: у строки кандидата заполнено ровно одно из двух полей —
 * product_id (обычный товар) или product_defect_id (партия уценки). Уникальность
 * при повторной генерации обеспечивают STORED-колонки product_key/defect_key
 * (COALESCE в 0): MySQL считает NULL-ы в уникальном индексе различными, и без
 * этого повторная доставка сообщения из RabbitMQ плодила бы дубли кандидатов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('substitution_offers', function (Blueprint $table) {
            $table->comment('Подборки замен по заказам с недобором: одна открытая подборка на заказ');

            $table->id()->comment('Первичный ключ');
            $table->uuid('uuid')->unique()->comment('Идентификатор для подписанной ссылки клиенту');

            $table->foreignId('order_id')
                ->comment('Исходный заказ с недобором (orders.id)')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->comment('Клиент-адресат на момент создания (users.id)')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('company_id')
                ->nullable()
                ->comment('Компания клиента на момент создания (companies.id)')
                ->constrained('companies')
                ->nullOnDelete();

            $table->foreignId('manager_user_id')
                ->nullable()
                ->comment('Персональный менеджер, ответственный за подборку (users.id); NULL — фолбэк-адресация')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status')
                ->default('pending')
                ->comment("Статус: 'pending' — ожидает, 'viewed' — клиент открыл, 'confirmed' — согласована, 'expired' — просрочена, 'dismissed' — закрыта без замены");

            $table->string('dismiss_reason')
                ->nullable()
                ->comment('Причина закрытия без замены (обязательна при dismissed)');

            $table->timestamp('expires_at')->comment('Срок жизни подборки (по умолчанию +7 дней от создания)');
            $table->timestamp('sent_at')->nullable()->comment('Когда менеджер отправил подборку клиенту (письмо)');
            $table->timestamp('reminded_at')->nullable()->comment('Когда ушло автонапоминание клиенту (строго одно)');
            $table->timestamp('call_task_at')->nullable()->comment('Когда поставлена задача «позвонить» по молчащей подборке (дожим)');
            $table->timestamp('viewed_at')->nullable()->comment('Когда клиент впервые открыл страницу подборки');
            $table->timestamp('confirmed_at')->nullable()->comment('Когда клиент согласовал замену');

            $table->json('result_order_ids')
                ->nullable()
                ->comment('Созданные заказы-замены (orders.id); их может быть два: обычный и уценочный');

            $table->foreignId('crm_email_id')
                ->nullable()
                ->comment('Письмо-черновик подборки в CRM (crm_emails.id); NULL — черновик удалён или не создавался')
                ->constrained('crm_emails')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись менялась');

            // Очередь CRM: открытые подборки менеджера, срочные сверху.
            $table->index(['status', 'manager_user_id'], 'substitution_offers_queue');
            $table->index(['order_id', 'status'], 'substitution_offers_order');
        });

        Schema::create('substitution_offer_items', function (Blueprint $table) {
            $table->comment('Строки подборки замен: кандидаты по каждой отменённой позиции заказа');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('offer_id')
                ->comment('Подборка (substitution_offers.id)')
                ->constrained('substitution_offers')
                ->cascadeOnDelete();

            $table->foreignId('source_order_item_id')
                ->comment('Отменённая строка заказа, которую закрываем (order_items.id)')
                ->constrained('order_items')
                ->cascadeOnDelete();

            // Без cascadeOnDelete: MySQL запрещает CASCADE/SET NULL на колонках,
            // входящих в STORED-generated столбцы (product_key/defect_key ниже).
            $table->foreignId('product_id')
                ->nullable()
                ->comment('Кандидат-товар (products.id); NULL — кандидат-уценка')
                ->constrained('products');

            $table->foreignId('product_defect_id')
                ->nullable()
                ->comment('Кандидат-партия уценки (product_defects.id); заполнено ровно одно из двух полей кандидата')
                ->constrained('product_defects');

            $table->string('kind')
                ->comment("Слой подбора: 'same_product_wait' — подождать прихода, 'defect_same' — уценка того же товара, 'linked' — подтверждённая связь, 'variant' — вариант модели, 'line' — линейка бренда, 'functional' — функциональный тип, 'category_price' — категория и цена, 'semantic' — семантика, 'manual' — менеджер вручную");

            $table->string('reason')->comment('Человекочитаемая причина замены — показывается клиенту');

            $table->decimal('price_snapshot', 15, 2)
                ->nullable()
                ->comment('Индивидуальная цена клиента на момент формирования подборки, руб.');

            $table->unsignedInteger('suggested_quantity')
                ->comment('Предлагаемое количество: отменённое либо доступный остаток, если он меньше');

            $table->timestamp('removed_by_manager_at')
                ->nullable()
                ->comment('Менеджер снял кандидата с подборки — негативный сигнал для пары товаров');

            $table->boolean('chosen')->default(false)->comment('Клиент выбрал этого кандидата');
            $table->unsignedInteger('chosen_quantity')
                ->nullable()
                ->comment('Выбранное клиентом количество (не больше suggested_quantity)');

            // Генерируемым столбцам комментарий не задаётся (правило db-comments):
            // это технические ключи для уникальности при NULL-ах в паре кандидата.
            $table->unsignedBigInteger('product_key')->storedAs('COALESCE(product_id, 0)');
            $table->unsignedBigInteger('defect_key')->storedAs('COALESCE(product_defect_id, 0)');

            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись менялась');

            $table->unique(
                ['offer_id', 'source_order_item_id', 'product_key', 'defect_key'],
                'substitution_offer_items_idempotency'
            );
        });

        Schema::create('product_substitutions', function (Blueprint $table) {
            $table->comment('Справочник связей замены «предлагать to вместо from»; связь направленная');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('from_product_id')
                ->comment('Товар, которого не хватает (products.id)')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('to_product_id')
                ->comment('Товар, который предлагается взамен (products.id)')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->string('kind')
                ->comment("Характер связи: 'variant' — вариант, 'line' — линейка, 'equivalent' — полный аналог, 'downgrade' — проще и дешевле, 'upgrade' — дороже, 'analog_volume' — другой объём/фасовка");

            $table->string('source')
                ->comment("Происхождение: 'manual' — менеджер, 'learned' — согласованный выбор клиента, 'ai' — ИИ-предразметка (требует подтверждения)");

            $table->unsignedTinyInteger('score')
                ->default(50)
                ->comment('Уверенность связи 0–100');

            $table->string('note')->nullable()->comment('Заготовка причины замены для клиента');

            $table->timestamp('confirmed_at')
                ->nullable()
                ->comment('Подтверждение человеком; для ai-связей обязательно до использования в автоподборе');

            $table->timestamp('rejected_at')
                ->nullable()
                ->comment('Связь отклонена — не предлагать и не создавать заново');

            $table->foreignId('created_by')
                ->nullable()
                ->comment('Кто завёл связь (users.id); NULL — система/агент')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись менялась');

            $table->unique(['from_product_id', 'to_product_id'], 'product_substitutions_pair');
        });

        Schema::create('substitution_events', function (Blueprint $table) {
            $table->comment('Плоский лог сигналов по кандидатам замен — обучающая выборка для тюнинга слоёв');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('offer_item_id')
                ->comment('Строка подборки, по которой сигнал (substitution_offer_items.id)')
                ->constrained('substitution_offer_items')
                ->cascadeOnDelete();

            $table->string('event')
                ->comment("Сигнал: 'manager_removed' — менеджер снял кандидата, 'client_chosen' — клиент выбрал, 'client_skipped' — клиент пропустил");

            $table->timestamp('created_at')->nullable()->comment('Когда сигнал зафиксирован');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substitution_events');
        Schema::dropIfExists('product_substitutions');
        Schema::dropIfExists('substitution_offer_items');
        Schema::dropIfExists('substitution_offers');
    }
};

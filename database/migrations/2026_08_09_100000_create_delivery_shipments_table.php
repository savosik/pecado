<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отправка груза транспортной компанией (интеграция с агрегатором ApiShip).
 *
 * Документ сайта, а не 1С: реализации (`shipments`) приходят из 1С, а решение
 * «эти пять реализаций едут одной машиной такой-то ТК» принимает склад. Поэтому
 * здесь свой номер, свой жизненный цикл и снапшоты адресов — заявка уже ушла
 * перевозчику, и последующая правка карточки клиента не должна её переписывать.
 *
 * `number` уезжает в ApiShip как `clientNumber` и служит ключом идемпотентности:
 * по нему приходят вебхуки и по нему же находится отправка при сверке статусов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_shipments', function (Blueprint $table) {
            $table->comment('Отправки груза транспортными компаниями (ApiShip)');

            $table->id()->comment('Первичный ключ');
            $table->string('number', 32)->nullable()->unique()
                ->comment('Номер отправки вида DS-000123. Уезжает в ApiShip как clientNumber — ключ идемпотентности');

            $table->foreignId('user_id')->nullable()
                ->comment('Клиент-получатель (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()
                ->comment('Организация клиента (companies.id) — для реквизитов получателя-юрлица')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()
                ->comment('Склад отправления (warehouses.id)')
                ->constrained('warehouses')->nullOnDelete();

            $table->string('status', 20)->default('draft')->index()
                ->comment("Внутренний статус: 'draft' — черновик, 'calculated' — тариф выбран, 'submitting' — заявка уходит в ТК, 'submitted' — принята ТК, 'in_transit' — в пути, 'delivered' — доставлена, 'cancelled' — отменена, 'failed' — ошибка передачи");

            // ─── Выбранная услуга доставки ───
            $table->string('provider_key', 50)->nullable()
                ->comment("Код службы доставки в ApiShip: 'cdek', 'dpd', 'dellin' и т.д.");
            $table->unsignedInteger('tariff_id')->nullable()->comment('Идентификатор тарифа в ApiShip');
            $table->string('tariff_name', 255)->nullable()->comment('Название тарифа на момент выбора (снапшот)');
            $table->unsignedTinyInteger('delivery_type')->default(1)
                ->comment('Тип доставки ApiShip: 1 — до двери получателя, 2 — до пункта выдачи');
            $table->unsignedTinyInteger('pickup_type')->default(1)
                ->comment('Тип забора ApiShip: 1 — забор курьером со склада, 2 — сдаём груз на терминал сами');
            $table->string('point_id', 50)->nullable()
                ->comment('Идентификатор пункта выдачи в ApiShip (только при delivery_type = 2)');
            $table->string('point_address', 500)->nullable()->comment('Адрес пункта выдачи (снапшот для карточки)');

            // ─── Идентификаторы на стороне ApiShip и перевозчика ───
            $table->string('apiship_order_id', 50)->nullable()->index()
                ->comment('Идентификатор заявки в ApiShip (orderId). Появляется после успешного POST /orders');
            $table->string('provider_number', 100)->nullable()->index()
                ->comment('Трек-номер в системе перевозчика. Создание заявки асинхронное — номер приезжает позже вебхуком');
            $table->string('barcode', 100)->nullable()->comment('Штрихкод отправления у перевозчика');
            $table->string('tracking_url', 500)->nullable()->comment('Публичная ссылка отслеживания — её же покажем клиенту в кабинете');

            // ─── Статус на стороне перевозчика ───
            $table->string('apiship_status_key', 32)->nullable()->index()
                ->comment("Ключ статуса ApiShip: 'uploaded', 'onWay', 'delivered' и т.д. (см. App\\Enums\\Delivery\\ApiShipStatus)");
            $table->string('apiship_status_name', 255)->nullable()->comment('Название статуса, как его прислал ApiShip');
            $table->dateTime('apiship_status_at')->nullable()->comment('Когда перевозчик зафиксировал текущий статус');

            // ─── Габариты и деньги ───
            $table->unsignedInteger('calculated_weight')->default(0)
                ->comment('Расчётный вес по товарам реализаций, граммы (products.weight_gross × количество)');
            $table->unsignedInteger('declared_weight')->nullable()
                ->comment('Фактический вес, заявленный кладовщиком, граммы. Именно он уходит в ТК, если задан');
            $table->unsignedSmallInteger('places_count')->default(0)->comment('Количество мест (коробок) в отправке');
            $table->decimal('assessed_cost', 12, 2)->default(0)
                ->comment('Объявленная ценность, рубли — сумма реализаций. От неё ТК считает страховку');
            $table->decimal('delivery_cost', 12, 2)->nullable()->comment('Стоимость доставки по выбранному тарифу, рубли');
            $table->decimal('delivery_cost_original', 12, 2)->nullable()
                ->comment('Стоимость до применения правил тарифного редактора ApiShip, рубли');

            $table->date('pickup_date')->nullable()->comment('Планируемая дата передачи груза перевозчику');

            // ─── Адреса ───
            // JSON, а не колонки: формат ApiShip (region/city/street/house/lat/lng/index)
            // нужен целиком и без потерь, а искать по нему мы не собираемся —
            // для поиска рядом лежат две денормализованные колонки.
            $table->json('sender')->nullable()->comment('Адрес и контакт отправителя в формате ApiShip (снапшот)');
            $table->json('recipient')->nullable()->comment('Адрес и контакт получателя в формате ApiShip (снапшот)');
            $table->string('recipient_city', 150)->nullable()->comment('ДЕНОРМАЛИЗАЦИЯ: город получателя для списка и фильтров');
            $table->string('recipient_contact', 255)->nullable()->comment('ДЕНОРМАЛИЗАЦИЯ: контактное лицо получателя для списка');

            $table->text('comment')->nullable()->comment('Комментарий склада к отправке (уезжает перевозчику в описании)');
            $table->text('last_error')->nullable()->comment('Текст последней ошибки от ApiShip — виден в карточке при статусе failed');

            $table->foreignId('created_by')->nullable()
                ->comment('Кто создал отправку (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()
                ->comment('Кто отправил заявку в ТК (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable()->comment('Когда заявка ушла в ТК');

            $table->timestamp('created_at')->nullable()->comment('Дата и время создания отправки');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения отправки');
            $table->softDeletes()->comment('Дата и время мягкого удаления черновика');

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_shipments');
    }
};

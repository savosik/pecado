<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расходные ордера на товары из 1С (US-20, протокол v15.15.0).
 *
 * Складской документ, по которому товар отбирают, проверяют, упаковывают и грузят.
 * Реализация (shipments) приезжает позже и отражает уже свершившийся факт отгрузки —
 * ордер нужен складу ДО неё.
 *
 * Три отличия от shipments, из-за которых это отдельная таблица, а не флаг на реализации:
 *   1) свой жизненный цикл статусов (Подготовлен → … → Отгружен) с журналом переходов;
 *   2) нет денежных полей — цены и суммы живут в реализации;
 *   3) получателем бывает контрагент, которого на сайте нет вовсе (маркетплейс,
 *      внутреннее перемещение), поэтому имя хранится строкой рядом со ссылкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_issues', function (Blueprint $table) {
            $table->comment('Расходные ордера на товары из 1С — складские документы отгрузки');

            $table->id()->comment('Первичный ключ');

            $table->char('uuid', 36)->unique()
                ->comment('UUID документа в 1С — источник правды и ключ идемпотентности');
            $table->string('number')->index()
                ->comment('Номер ордера в 1С, например «УТ-00009419». Не уникален: нумерация может пересекаться между префиксами');

            $table->dateTime('date')->nullable()->index()
                ->comment('Дата и время документа («от» в шапке ордера)');
            $table->dateTime('shipment_date')->nullable()->index()
                ->comment('Дата отгрузки из шапки. Может отличаться от даты документа: ордер создаётся заранее, а грузится позже');

            $table->string('status', 32)->index()
                ->comment("Статус ордера: 'prepared' — Подготовлен, 'to_pick' — К отбору, 'to_check' — К проверке, 'checked' — Проверен, 'to_ship' — К отгрузке, 'shipped' — Отгружен");
            $table->dateTime('status_changed_at')->nullable()->index()
                ->comment('Когда статус сменился в последний раз. По нему склад находит ордера, зависшие на этапе');

            $table->string('operation')->nullable()
                ->comment('Хозяйственная операция документа как есть, например «Отгрузка клиенту». Свободная строка: операций больше, чем отгрузка клиенту');

            $table->foreignId('warehouse_id')->nullable()->comment('Склад отгрузки (warehouses.id). Пусто, если склад из 1С сайту неизвестен')->constrained()->nullOnDelete();
            $table->char('warehouse_uuid', 36)->nullable()
                ->comment('UUID склада в 1С. Хранится всегда, даже когда warehouse_id не резолвится — по нему связь доклеивается после заведения склада');

            $table->foreignId('organization_id')->nullable()->comment('Наша организация — юрлицо, от имени которого документ проведён в 1С (organizations.id)')->constrained('organizations')->nullOnDelete();

            $table->foreignId('company_id')->nullable()->comment('Контрагент-получатель (companies.id). Пусто, если получателя нет в справочниках сайта')->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->comment('Пользователь-владелец контрагента (users.id)')->constrained()->nullOnDelete();
            $table->char('contractor_uuid', 36)->nullable()->index()
                ->comment('UUID контрагента в 1С. Хранится всегда, даже когда company_id не резолвится');
            $table->string('tax_id')->nullable()
                ->comment('ИНН получателя. Запасной способ матчинга контрагента — только в паре с user_id');
            $table->string('recipient_name')->nullable()
                ->comment('Получатель строкой, как в шапке ордера. Заполняется всегда: получателем бывает контрагент, которого на сайте нет (маркетплейс), и без имени ордер в журнале безымянный');

            $table->string('responsible')->nullable()->index()
                ->comment('Ответственный за ордер — пользователь или бригада отгрузки в 1С, например «Отгрузка 3 Москва»');
            $table->string('priority', 16)->nullable()
                ->comment("Приоритет: 'low' — Низкий, 'normal' — Средний, 'high' — Высокий");
            $table->text('comment')->nullable()
                ->comment('Комментарий из шапки ордера');

            $table->string('delivery_type', 16)->nullable()
                ->comment("Способ передачи товара: 'pickup' — Самовывоз, 'delivery' — Доставка");
            $table->string('delivery_address')->nullable()
                ->comment('Адрес доставки («Доставка по …»)');
            $table->string('delivery_order')->nullable()
                ->comment('Порядок доставки из нижней части документа');

            $table->unsignedInteger('packages_count')->default(0)
                ->comment('ДЕНОРМАЛИЗАЦИЯ: число упаковочных мест в ордере. Журнал сортируется и фильтруется по нему — считать на лету на каждой странице незачем');
            $table->unsignedInteger('items_count')->default(0)
                ->comment('ДЕНОРМАЛИЗАЦИЯ: число строк товаров в ордере');
            $table->decimal('total_quantity', 15, 3)->default(0)
                ->comment('ДЕНОРМАЛИЗАЦИЯ: суммарное количество товара по всем строкам');
            $table->unsignedInteger('unresolved_items_count')->default(0)
                ->comment('ДЕНОРМАЛИЗАЦИЯ: сколько строк не привязалось к товару каталога. Ненулевое значение — сигнал рассинхрона каталога с 1С');

            $table->dateTime('erp_created_at')->nullable()
                ->comment('Аудит-метка 1С: когда документ создан в учётной системе');
            $table->dateTime('erp_updated_at')->nullable()
                ->comment('Аудит-метка 1С: когда документ изменён в последний раз');

            $table->timestamp('created_at')->nullable()->comment('Дата и время создания записи на сайте (момент приёма сообщения из 1С)');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения записи на сайте');
            $table->softDeletes()
                ->comment('Мягкое удаление: 1С отменила проведение документа. Состав и история статусов сохраняются — повторное проведение вернёт ордер как был');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_issues');
    }
};

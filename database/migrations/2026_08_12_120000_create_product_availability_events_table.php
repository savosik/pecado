<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История доступности товара: переходы «появился» / «кончился».
 *
 * До этой таблицы истории остатков в проекте не было вовсе. Остаток живёт
 * только в pivot `product_warehouse.quantity`, а HandleStockUpdated
 * перезаписывает его абсолютным значением из 1С — старое количество уходило
 * лишь в лог. Ответить на вопрос «сколько дней товара не было и когда он
 * вернулся» было нечем.
 *
 * **Пишем переходы, а не снимки.** `stock.updated` идёт потоком: supervisor
 * держит двенадцать процессов на очереди цен и шесть на каталоге. Строка
 * на каждое сообщение дала бы таблицу, которая съест базу — проект это уже
 * проходил с Pulse, чьи таблицы занимали 5,8 ГБ из 6,6 ГБ боевой базы
 * и растягивали pre-deploy бэкап. Поэтому запись появляется только тогда,
 * когда доступность действительно сменилась.
 *
 * Ретенция закладывается сразу, а не «когда разрастётся»: команда
 * `stock:cleanup-availability` в расписании.
 *
 * Контракт шины не меняется: событие stock.updated остаётся прежним, новых
 * событий AsyncAPI нет — spec-first правки и ERP-интеграционные тесты
 * к этой таблице не применяются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_availability_events', function (Blueprint $table) {
            $table->comment('Переходы доступности товара: когда появился в продаже и когда кончился');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('product_id')
                ->comment('Товар (products.id)')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->string('event', 20)
                ->comment("Что произошло: 'in_stock' — товар появился в продаже, 'out_of_stock' — кончился");

            $table->unsignedInteger('quantity')
                ->comment('Суммарный доступный остаток на момент перехода, штук');

            $table->timestamp('happened_at')->comment('Когда переход зафиксирован');

            // Сколько дней товара не было — считается при появлении, чтобы
            // потребителю не приходилось искать парное событие в истории.
            $table->unsignedInteger('missing_days')->nullable()
                ->comment("Для 'in_stock' — сколько дней товара не было; NULL для 'out_of_stock' и для первого появления");

            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись менялась');

            // Основной запрос — «что вернулось в продажу за последние N дней»,
            // отсюда порядок колонок в индексе.
            $table->index(['event', 'happened_at'], 'product_availability_events_event_index');
            $table->index(['product_id', 'happened_at'], 'product_availability_events_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_availability_events');
    }
};

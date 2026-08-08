<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Идентификатор строки заказа и признак отмены при недоборе (протокол v15.16.0).
 *
 * `line_number` — номер строки в табличной части «Товары» документа 1С. До него
 * строку заказа нечем было идентифицировать: обработчики order.created/updated
 * удаляли все позиции и создавали заново, а две строки на один товар (недобор)
 * схлопывались в одну. Отсюда же два трейта-костыля, переносивших складские
 * привязки по product_id, — с этим полем они не нужны.
 *
 * Индекс (order_id, line_number) НЕ уникальный намеренно: заказы, оформленные
 * на сайте до v15.16.0, номеров строк не имеют, а 1С теоретически может прислать
 * дубль номера. Уникальный ключ превратил бы такое сообщение в падение обработки;
 * разруливается в коде, как у payment_allocations.
 *
 * `cancelled` — строка отменена в 1С при недоборе. 1С присылала признак и раньше,
 * но сайт его не читал, и отменённое количество попадало в сумму заказа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('line_number')->nullable()->after('order_id')
                ->comment('Номер строки в табличной части «Товары» документа 1С — ключ сопоставления позиции при обновлении заказа. NULL у позиций, оформленных на сайте до v15.16.0');
            $table->boolean('cancelled')->default(false)->after('quantity')
                ->comment('Строка отменена в 1С при недоборе (товара не хватило на складе): позиция сохраняется и показывается клиенту, но не входит в сумму заказа');

            $table->index(['order_id', 'line_number'], 'order_items_order_line_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_line_index');
            $table->dropColumn(['line_number', 'cancelled']);
        });
    }
};

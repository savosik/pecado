<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Строки расходного ордера — табличная часть «Товары по распоряжениям» (US-20).
 *
 * Заказ указывается здесь, а не в шапке: один ордер может собираться сразу
 * по нескольким заказам клиента (так же, как реализация). Связь «ордер ↔ заказ»
 * строится через эти строки.
 *
 * Ссылки на товар и заказ nullable, а рядом лежат снимки имени и номера из 1С:
 * ордер по товару, которого нет в каталоге сайта, кладовщику всё равно нужно видеть.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_issue_items', function (Blueprint $table) {
            $table->comment('Строки расходных ордеров — товары по распоряжениям');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('goods_issue_id')->comment('Расходный ордер (goods_issues.id)')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('line_number')->nullable()
                ->comment('Номер строки табличной части («N»). Если 1С не прислала — нумеруется по порядку в массиве');

            $table->foreignId('product_id')->nullable()->comment('Товар каталога (products.id). Пусто, если номенклатуры нет на сайте')->constrained()->nullOnDelete();
            $table->char('product_uuid', 36)->index()
                ->comment('UUID номенклатуры в 1С. Хранится всегда — по нему связь доклеивается после выгрузки товара');
            $table->string('product_name')->nullable()
                ->comment('СНИМОК наименования из 1С на момент отгрузки. Без него строка по отсутствующему в каталоге товару нечитаема');

            $table->foreignId('order_id')->nullable()->comment('Заказ-распоряжение (orders.id). Пусто, если заказа нет на сайте')->constrained()->nullOnDelete();
            $table->char('order_uuid', 36)->nullable()->index()
                ->comment('UUID заказа-распоряжения в 1С — источник правды связи ордера с заказом');
            $table->string('order_number')->nullable()
                ->comment('СНИМОК номера заказа из 1С, например «30УТ-000213»');
            $table->dateTime('order_date')->nullable()
                ->comment('Дата заказа-распоряжения');

            $table->decimal('quantity', 15, 3)->default(0)
                ->comment('Количество к отгрузке по строке');
            $table->string('unit', 32)->nullable()
                ->comment('Единица измерения, например «шт»');
            $table->unsignedInteger('package_number')->nullable()
                ->comment('Номер упаковочного листа, в который попала строка (goods_issue_packages.number в пределах ордера)');

            $table->timestamp('created_at')->nullable()->comment('Дата и время создания строки на сайте');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения строки на сайте');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_issue_items');
    }
};

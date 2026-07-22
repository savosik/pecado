<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Партии некондиции (уценки).
 *
 * Одна запись = один вид дефекта у одного товара + количество + общие фотографии.
 * Товар одного артикула может иметь разные дефекты (порвана упаковка, нет крышки
 * батарейного отсека), поэтому у товара может быть несколько партий.
 *
 * Учёт ведёт кладовщик в /wms/, цену и видимость на сайте задаёт закупщик в /admin/.
 * Фотографии — Spatie MediaLibrary, коллекция `defect_photos`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_defects', function (Blueprint $table) {
            $table->comment('Партии некондиции (уценки): дефект + количество + цена');

            $table->id()->comment('Первичный ключ');
            $table->uuid('uuid')->unique()->comment('Внешний идентификатор партии — используется в ссылках WMS и на витрине');

            $table->foreignId('product_id')->comment('Товар (products.id)')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->comment('Склад некондиции (warehouses.id), где физически лежит партия')
                ->constrained()->cascadeOnDelete();

            $table->text('defect_description')->comment('Описание дефекта от кладовщика, например «порвана упаковка»');
            $table->unsignedInteger('quantity')->default(1)->comment('Количество единиц в партии, шт');

            $table->decimal('price', 10, 2)->nullable()
                ->comment('Цена уценки за штуку в рублях; NULL — цена ещё не назначена закупщиком');
            $table->boolean('is_published')->default(false)
                ->comment('Видимость на сайте: включает закупщик, разрешено только при заполненной цене');

            $table->timestamp('closed_at')->nullable()
                ->comment('Момент закрытия партии: распродана или списана; закрытая партия не продаётся');
            $table->string('closed_reason')->nullable()
                ->comment("Причина закрытия: 'sold_out' — распродана, 'written_off' — списана вручную; NULL пока партия открыта");

            $table->foreignId('created_by')->nullable()->comment('Кладовщик, заведший партию (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('priced_by')->nullable()->comment('Закупщик, назначивший цену (users.id)')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Дата заведения партии');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');
            $table->softDeletes()->comment('Мягкое удаление партии');

            // Витрина спрашивает «есть ли у товара опубликованные открытые партии»
            $table->index(['product_id', 'is_published', 'closed_at'], 'product_defects_showcase_idx');
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_defects');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Страховой буфер остатков по рисковым товарам (эпик buf-00).
 *
 * Буфер виртуальный: `product_warehouse.quantity` остаётся абсолютным значением
 * из 1С, занижается только *показ* остатка клиентам с галочкой
 * `users.stock_buffer_enabled` (появится в buf-02). Одна запись = один товар.
 *
 * `buffer_qty` пересчитывает ночная команда `stock:buffers:recompute` по сигналам
 * (отмены строк при сборке, партии брака, срок годности); `manual_qty` задаёт
 * склад в WMS-консоли (buf-06) и он всегда побеждает расчёт.
 *
 * После прод-миграции — `bi:sync-grants`, иначе ИИ-агент менеджеров таблицу не увидит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_buffers', function (Blueprint $table) {
            $table->comment('Страховой буфер остатков: виртуальное занижение показа по рисковым товарам для клиентов сегмента (users.stock_buffer_enabled)');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('product_id')->unique()->comment('Товар (products.id); не более одной записи на товар')
                ->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('buffer_qty')->default(0)
                ->comment('Рассчитанный размер буфера, шт: на столько занижается показ остатка; 0 — сигналы есть, но остаток слишком мал для занижения');
            $table->unsignedSmallInteger('manual_qty')->nullable()
                ->comment('Ручной размер буфера со склада (WMS-консоль), шт; задан — побеждает расчётный buffer_qty; NULL — действует расчёт');

            $table->json('reasons')->nullable()
                ->comment('Раскладка сигналов риска для WMS-консоли: {"cancellations": N отмен за 90 дн, "defect_batches": N партий брака, "shelf_life": true — срок годности ближе порога}');

            $table->timestamp('computed_at')->nullable()
                ->comment('Когда буфер в последний раз изменён командой stock:buffers:recompute; NULL — запись создана вручную и ещё не пересчитывалась');

            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_buffers');
    }
};

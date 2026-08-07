<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Упаковочные листы (места) расходного ордера — вкладка «Отгружаемые товары» (US-20).
 *
 * Хранится состав по местам укрупнённо: номер листа и число позиций в нём.
 * Построчная раскладка не нужна — для сверки при погрузке хватает «Всего в ордере: N мест»
 * и состава каждого места, а привязка строки к месту лежит в goods_issue_items.package_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_issue_packages', function (Blueprint $table) {
            $table->comment('Упаковочные листы (места) расходных ордеров');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('goods_issue_id')->comment('Расходный ордер (goods_issues.id)')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('number')
                ->comment('Номер упаковочного листа в пределах ордера («Упаковочный лист 3»)');
            $table->unsignedInteger('positions_count')->nullable()
                ->comment('Сколько позиций в составе листа («2 позиции в составе»)');
            $table->decimal('weight', 12, 3)->nullable()
                ->comment('Вес места. Обычно пусто — в 1С колонка часто не используется');
            $table->decimal('volume', 12, 3)->nullable()
                ->comment('Объём места. Обычно пусто — в 1С колонка часто не используется');

            $table->timestamp('created_at')->nullable()->comment('Дата и время создания записи об упаковочном листе');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения записи об упаковочном листе');

            $table->unique(['goods_issue_id', 'number'], 'goods_issue_packages_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_issue_packages');
    }
};

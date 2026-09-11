<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Приказы с переменными Приложения № 1 Положения о мотивации (эпик mot-00, карточка mot-20).
 *
 * Четвёртый слой параметров поверх трёх существующих в payroll: приказ на отдел,
 * действующий с даты, а не отклонение по человеку. Ранее изданные приказы не правятся —
 * расчёт прошлого периода читается по приказу, действовавшему тогда.
 *
 * Значения хранятся одним JSON, а не строками «параметр — значение», осознанно: набор
 * меняется целиком приказом, версия обязана быть атомарной. Отдельные строки допускали бы
 * полуприменённое состояние, при котором ставка новая, а порог ещё старый.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_parameter_orders', function (Blueprint $table) {
            $table->comment('Приказы с числовыми параметрами Положения о мотивации: ставки, пороги, ступени, сроки, сезонные коэффициенты');
            $table->id()->comment('Первичный ключ');
            $table->date('effective_from')->unique()->comment('Первый расчётный период действия приказа (1-е число месяца)');
            $table->string('order_number', 64)->nullable()->comment('Номер приказа');
            $table->date('order_date')->nullable()->comment('Дата приказа');
            $table->json('values')->comment('Значения всех параметров Приложения № 1 одним набором: оклад, надбавки, порог оплаты, ставки П1/П2/П3/К1, период новизны, льготный период, потолок переменной части, ступени квартальной премии, параметры пула и планирования, сроки и гарантии');
            $table->string('comment', 500)->nullable()->comment('Основание изменения');
            $table->foreignId('author_id')->nullable()->comment('Кто ввёл приказ (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_parameter_orders');
    }
};

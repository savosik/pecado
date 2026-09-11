<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Исключения задолженности из базы начисления вычета К1 (эпик mot-00, карточка mot-20).
 *
 * Заведена по решению заказчика от 08.09.2026: старая задолженность расчищается до введения
 * системы — безнадёжная списывается, по остальной ведётся претензионная работа. И то, и другое
 * обязано выводить долг из базы начисления, иначе показатель продолжает считаться на
 * задолженность, которой работник уже не занимается. Стартовая расчистка — 53 накладные
 * у 11 партнёров на 1 524 тыс ₽.
 *
 * Признака такого рода в данных нет вовсе: система различает только «оплачено» и «не оплачено».
 *
 * Возврат долга в базу (партнёр признал долг, пошёл платёж) оформляется закрытием исключения
 * датой, а не удалением строки: история обязана сохраняться, иначе разбор прошлого расчёта
 * упирается в исчезнувшее основание.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_debt_exclusions', function (Blueprint $table) {
            $table->comment('Задолженность, выведенная из базы начисления вычета К1: списанная, переданная в претензионную работу или оспариваемая');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('shipment_id')->nullable()->comment('Документ отгрузки (shipments.id); NULL — исключение по партнёру целиком')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('Партнёр (users.id)')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 20)->comment("Основание: 'written_off' — списан безнадёжным, 'legal' — передан в претензионную работу, 'disputed' — оспаривается партнёром, 'other' — иное с пояснением");
            $table->date('excluded_from')->comment('С какого дня долг не участвует в начислении вычета');
            $table->date('excluded_until')->nullable()->comment('По какой день исключён включительно; NULL — бессрочно. Возврат в базу оформляется этой датой, а не удалением строки');
            $table->decimal('amount', 15, 2)->nullable()->comment('Сумма исключения, ₽, если из базы выводится часть долга; NULL — весь остаток документа');
            $table->string('document_ref', 255)->nullable()->comment('Ссылка на основание: приказ о списании, номер претензии, номер дела');
            $table->string('comment', 500)->nullable()->comment('Пояснение к исключению');
            $table->foreignId('author_id')->nullable()->comment('Кто исключил (users.id) — право только у руководителя')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->index(['user_id', 'excluded_from'], 'motivation_debt_exclusions_user_idx');
            $table->index('shipment_id', 'motivation_debt_exclusions_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_debt_exclusions');
    }
};

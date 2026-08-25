<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Разовый зачёт авансов по заказам в уже накопленных строках графика.
 *
 * **Двигатель снят в fin-11** вместе со старым счётным ядром: `PaymentScheduleService`
 * и таблица `shipment_payment_schedules` удалены, зачёт авансов делает 1С, а сайт
 * читает готовый `settled_amount` из регистра.
 *
 * Тело оставлено пустым, а не удалена сама миграция: она уже применена на dev
 * и на проде, и её запись в `migrations` должна иметь файл. На свежей базе
 * (тесты, новый стенд) зачитывать всё равно нечего.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Намеренно пусто — см. комментарий выше.
    }

    public function down(): void
    {
        // Пусто намеренно.
    }
};

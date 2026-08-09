<?php

use App\Models\ShipmentPaymentSchedule;
use App\Services\Payments\PaymentScheduleService;
use Illuminate\Database\Migrations\Migration;

/**
 * Разовый зачёт авансов по заказам в уже накопленных строках графика.
 *
 * Колонку `prepaid_amount` добавила соседняя миграция, но заполняется она только
 * при поступлении новых сообщений из 1С. Исторические строки так и остались бы
 * просроченными: на 2026-08-09 это 8845 строк графика по 5592 заказам, а раздел
 * «Финансы» показывал по ним просрочку, которой в учёте нет (у одного клиента
 * 5,53 млн против 478 тыс по данным 1С).
 *
 * Данные, а не схема: команда `payments:recalculate` делает ровно то же самое, но
 * запускать её на проде вручную некому — прод правится только через CI.
 *
 * Идемпотентно: зачёт — полная функция от состояния БД, повторный прогон даёт тот
 * же результат. Поэтому у миграции нет и обратного действия: down() вернул бы
 * нули, которые тут же перезаписал бы первый платёж из 1С.
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(PaymentScheduleService::class);

        ShipmentPaymentSchedule::query()
            ->whereNotNull('order_uuid')
            ->distinct()
            ->pluck('order_uuid')
            ->chunk(500)
            ->each(fn ($batch) => $service->applyOrderPrepayments($batch->all()));
    }

    public function down(): void
    {
        // Пусто намеренно: см. комментарий выше.
    }
};

<?php

namespace App\Services\Erp\Handlers;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Обработка события payment.deleted из 1С (US-17, протокол v15.11.0).
 *
 * Мягкое удаление: строки расшифровки намеренно остаются в БД. Пересчёт оплаты
 * уже фильтрует удалённые платежи, а повторное проведение того же документа
 * должно вернуть разнесение как было — без повторной доставки расшифровки из 1С.
 */
class HandlePaymentDeleted
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandlePaymentDeleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $payment = Payment::where('uuid', $uuid)->first();

        if (! $payment) {
            Log::info('HandlePaymentDeleted: платёж не найден', ['uuid' => $uuid]);

            return;
        }

        $payment->delete();

        // Пересчёт оплат реализаций не нужен: погашения считает регистр,
        // и отмена проведения приходит своим событием (settlement.reverted).

        Log::info('HandlePaymentDeleted: платёж удалён', ['uuid' => $uuid]);
    }
}

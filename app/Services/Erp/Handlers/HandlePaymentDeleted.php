<?php

namespace App\Services\Erp\Handlers;

use App\Models\Payment;
use App\Services\Payments\PaymentAllocationService;
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

        // Реализации собираем ДО удаления: после него связь останется, но искать
        // её будет уже незачем — пересчитывать надо ровно те, что теряют оплату.
        $affected = $payment->allocations()->whereNotNull('shipment_id')->pluck('shipment_id')->all();

        $payment->delete();

        app(PaymentAllocationService::class)->recalculateShipments($affected);

        Log::info('HandlePaymentDeleted: платёж удалён', [
            'uuid' => $uuid,
            'shipments_recalculated' => count($affected),
        ]);
    }
}

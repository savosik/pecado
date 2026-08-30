<?php

namespace App\Observers;

use App\Events\Payroll\PayrollInputsChanged;
use App\Models\Shipment;
use App\Models\User;

/**
 * Реализация появилась или изменилась → входы зарплаты менеджера партнёра изменились.
 *
 * Проекция оплаты (SettlementProjector) пишет через saveQuietly и сюда не попадает —
 * по деньгам зарплату будит мост накладных. Здесь только сама отгрузка.
 */
class PayrollShipmentObserver
{
    public function created(Shipment $shipment): void
    {
        $this->notify($shipment, 'shipment.created');
    }

    public function updated(Shipment $shipment): void
    {
        $this->notify($shipment, 'shipment.updated');
    }

    public function deleted(Shipment $shipment): void
    {
        $this->notify($shipment, 'shipment.deleted');
    }

    public function restored(Shipment $shipment): void
    {
        $this->notify($shipment, 'shipment.restored');
    }

    private function notify(Shipment $shipment, string $source): void
    {
        if ($shipment->user_id === null) {
            return;
        }

        $managerId = User::query()->whereKey($shipment->user_id)->value('personal_manager_id');

        if ($managerId === null) {
            return;
        }

        $month = $shipment->erp_created_at?->copy()->startOfMonth()->toDateString();

        PayrollInputsChanged::dispatch([(int) $managerId], $source, $month === null ? [] : [$month]);
    }
}

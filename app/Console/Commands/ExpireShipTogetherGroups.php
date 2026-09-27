<?php

namespace App\Console\Commands;

use App\Enums\ShipTogetherStatus;
use App\Models\Order;
use App\Services\Order\ShipTogetherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Страховка сайта для совместной отгрузки (v16.11.0).
 *
 * 1С отвечает итогом группы не позже 15 минут от первого сообщения, сайт ждёт
 * дольше (order_reserve.ship_together.pending_timeout_minutes). Если итог так и
 * не пришёл — сообщение потерялось или обмен стоял — заказы возвращаются в
 * окно резерва с локальной причиной `no_response`, чтобы клиент не завис
 * в «ждём подтверждения склада» до истечения резерва. Поздний итог из 1С,
 * если он всё же придёт, перепишет это состояние: reserve авторитетен.
 */
class ExpireShipTogetherGroups extends Command
{
    protected $signature = 'reserve:ship-together-timeout';

    protected $description = 'Вернуть в резерв группы совместной отгрузки, по которым 1С не ответила за отведённое время';

    public function handle(ShipTogetherService $service): int
    {
        $minutes = max(1, (int) config('order_reserve.ship_together.pending_timeout_minutes', 60));
        $deadline = now()->subMinutes($minutes);

        $keys = Order::query()
            ->where('ship_together_status', ShipTogetherStatus::PENDING->value)
            ->where('ship_together_sent_at', '<=', $deadline)
            ->distinct()
            ->pluck('ship_together_key');

        $groups = 0;

        foreach ($keys as $key) {
            DB::transaction(function () use ($key, $service, &$groups): void {
                $orders = Order::query()
                    ->where('ship_together_key', $key)
                    ->where('ship_together_status', ShipTogetherStatus::PENDING->value)
                    ->lockForUpdate()
                    ->get();

                foreach ($orders as $order) {
                    $service->markNoResponse($order);
                    $order->save();
                }

                if ($orders->isNotEmpty()) {
                    $groups++;
                }
            });
        }

        $this->info("Групп без ответа возвращено в резерв: {$groups}");

        return self::SUCCESS;
    }
}

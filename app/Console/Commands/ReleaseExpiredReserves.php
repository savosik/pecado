<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Crm\Mail\MailStream;
use App\Services\Erp\OrderReservePublisher;
use App\Support\Notifications\Occasion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Авто-снятие просроченных резервов + предупреждения (v16.9.0, res-09).
 *
 * Основной таймер режима «Заказы в резерве» — у сайта (по resolution топика №5):
 * по истечении reserved_until сайт шлёт order.deleted с reason=reserve_expired,
 * страховочный регламент 1С (+6 часов грации) в норме не срабатывает никогда.
 *
 * Дедлайн — главный предохранитель режима: без него интернетчики держали бы
 * резервы неделями «на всякий случай».
 *
 * Команда работает НЕЗАВИСИМО от рубильника order_reserve.enabled: если режим
 * аварийно выключили, уже висящие резервы обязаны дожить штатно — доснять их
 * важнее, чем не показывать кнопки.
 */
class ReleaseExpiredReserves extends Command
{
    protected $signature = 'reserve:release-expired';

    protected $description = 'Снять просроченные резервы заказов и предупредить клиентов об истекающих';

    public function handle(OrderReservePublisher $publisher, MailStream $mailStream): int
    {
        $this->warnExpiring($mailStream);

        $expired = Order::query()
            ->where('reserve', true)
            ->where('reserved_until', '<', now())
            ->orderBy('reserved_until')
            ->get();

        $released = 0;

        foreach ($expired as $order) {
            // Гонка с подтверждением клиента в последнюю секунду: перечитываем
            // под блокировкой и снимаем только из состояния «в резерве».
            $done = DB::transaction(function () use ($order, $publisher) {
                $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();

                if ($fresh === null || ! $fresh->reserve || $fresh->trashed()) {
                    return false;
                }

                $publisher->publishDeleted($fresh, OrderReservePublisher::REASON_RESERVE_EXPIRED);

                // Комментарий уходит в OrderStatusHistory (booted::updating) —
                // менеджер и клиент видят, что снятие автоматическое.
                request()->merge(['status_comment' => 'Резерв истёк — заказ снят автоматически']);

                $fresh->reserve = false;
                $fresh->status = OrderStatus::CLOSED;
                $fresh->save();
                $fresh->deleteQuietly();

                return true;
            });

            if (! $done) {
                continue;
            }

            $released++;

            $number = $order->erp_number ?: $order->number ?: ('#'.$order->id);
            $mailStream->captureQuietly(new Occasion(
                key: 'orders.reserve_released',
                clientUserId: $order->user_id,
                companyId: $order->company_id,
                subject: $order,
                data: [
                    'order_number' => $number,
                    'reserved_until' => $order->reserved_until?->toIso8601String(),
                ],
                view: [
                    'title' => sprintf('Заказ %s: резерв снят', $number),
                    'body' => sprintf(
                        'Срок резерва по заказу %s истёк, заказ отменён автоматически — товар вернулся в свободный остаток. Нужен снова — оформите заказ заново.',
                        $number,
                    ),
                    'url' => url('/cabinet/orders'),
                    'entity_label' => "Заказ {$number}",
                ],
            ));
        }

        $this->info(sprintf('Снято резервов: %d из %d просроченных.', $released, $expired->count()));

        return self::SUCCESS;
    }

    /**
     * Предупреждение «резерв истекает» — за expiring_notice_hours до конца.
     *
     * Письмо однократное на заказ: origin_key в MailStream строится из ключа
     * повода, клиента и order_number — повторный прогон дубля не создаёт.
     */
    private function warnExpiring(MailStream $mailStream): void
    {
        $noticeHours = (int) config('order_reserve.expiring_notice_hours', 3);

        if ($noticeHours <= 0) {
            return;
        }

        $expiring = Order::query()
            ->where('reserve', true)
            ->whereBetween('reserved_until', [now(), now()->addHours($noticeHours)])
            ->get();

        foreach ($expiring as $order) {
            $number = $order->erp_number ?: $order->number ?: ('#'.$order->id);
            $until = $order->reserved_until?->timezone(config('app.timezone'));

            $mailStream->captureQuietly(new Occasion(
                key: 'orders.reserve_expiring',
                clientUserId: $order->user_id,
                companyId: $order->company_id,
                subject: $order,
                data: [
                    'order_number' => $number,
                    'reserved_until' => $until?->format('d.m.Y H:i'),
                ],
                view: [
                    'title' => sprintf('Заказ %s: резерв истекает %s', $number, $until?->format('d.m.Y в H:i')),
                    'body' => sprintf(
                        'Резерв по заказу %s истекает %s. Подтвердите отгрузку в кабинете — иначе резерв снимется автоматически и товар вернётся в свободный остаток.',
                        $number,
                        $until?->format('d.m.Y в H:i'),
                    ),
                    'url' => url(route('cabinet.orders.show', $order, false)),
                    'entity_label' => "Заказ {$number}",
                ],
            ));
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\DeliveryMethod;
use App\Listeners\Pickup\NotifyClientAboutPickup;
use App\Models\GoodsIssue;
use App\Services\Pickup\OrderFulfilmentResolver;
use Illuminate\Console\Command;

/**
 * Напоминание клиенту о собранном и не забранном заказе (решение заказчика 18.09.2026).
 *
 * Собранное храним сколько угодно, но молчать нельзя: клиент мог забыть. Письмо уходит на ступенях
 * `pickup.waiting_reminder_days` (3, 7, 14, 30-й день). Ступень входит в ключ письма, поэтому повторный
 * запуск и пропущенный день дублей и дыр не дают: заказ на 5-й день получит письмо ступени 3, если его ещё не было.
 */
class PickupRemindWaiting extends Command
{
    protected $signature = 'pickup:remind-waiting {--dry-run : Показать, кому ушло бы напоминание}';

    protected $description = 'Напомнить клиентам о собранных заказах самовывоза, которые давно не забирают';

    public function handle(OrderFulfilmentResolver $resolver, NotifyClientAboutPickup $notifier): int
    {
        if (! config('pickup.enabled') && ! $this->option('dry-run')) {
            $this->warn('Самовывоз выключен (PICKUP_ENABLED=false) — напоминания не уходят.');

            return self::SUCCESS;
        }

        $steps = collect((array) config('pickup.waiting_reminder_days', [3, 7, 14, 30]))->map(fn ($d) => (int) $d)->filter()->sort()->values();
        if ($steps->isEmpty()) {
            return self::SUCCESS;
        }

        // Без даты отсечения «не забранными» считались бы все самовывозы за всю историю: у них нет отметки
        // «выдан», потому что экрана выдачи раньше не было. Массовое письмо клиентам о давно забранных заказах
        // недопустимо — без PICKUP_HANDOVER_SINCE команда не работает.
        $since = $resolver->handoverSince();
        if ($since === null) {
            $this->error('Не задан PICKUP_HANDOVER_SINCE — напоминания не отправляются, чтобы не разослать письма по старым заказам.');

            return self::FAILURE;
        }
        $issues = GoodsIssue::query()
            ->where('status', GoodsIssue::STATUS_SHIPPED)
            ->where('status_changed_at', '<=', now()->subDays($steps->first()))
            ->when($since, fn ($q) => $q->where('status_changed_at', '>=', $since))
            ->whereDoesntHave('activeHandover')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('goods_issue_items')
                ->join('orders', 'orders.uuid', '=', 'goods_issue_items.order_uuid')
                ->whereColumn('goods_issue_items.goods_issue_id', 'goods_issues.id')
                ->where('orders.delivery_method', DeliveryMethod::PICKUP->value))
            ->get();

        $sent = 0;
        foreach ($issues as $issue) {
            $days = (int) $issue->status_changed_at->diffInDays(now());
            $step = $steps->filter(fn (int $s) => $s <= $days)->last();
            if ($step === null) {
                continue;
            }

            $this->line(sprintf('ордер %s ждёт %d дн. → ступень %d', $issue->number, $days, $step));
            if (! $this->option('dry-run')) {
                $sent += $notifier->remindWaiting($issue, $step);
            }
        }

        $this->info($this->option('dry-run') ? "Кандидатов: {$issues->count()} (dry-run)" : "Поводов передано в поток писем: {$sent}.");

        return self::SUCCESS;
    }
}

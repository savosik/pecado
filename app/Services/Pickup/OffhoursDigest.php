<?php

namespace App\Services\Pickup;

use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\GoodsIssueStatusHistory;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\Pickup\PickupHandover;
use App\Models\User;
use App\Services\Crm\ManagerAbsenceResolver;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Утренняя сводка менеджеру (pick-13): что произошло по его клиентам, пока он не работал.
 *
 * Склад работает до 21:00 и по субботам, менеджер — пн–пт до 18:00. Клиенты вечером сами
 * отправляют заказы, забирают их и теряют резервы; менеджер должен узнать об этом утром
 * из одного письма, а не от клиента. Адресат — по данным: персональный менеджер клиента,
 * на время отсутствия — замещающий.
 */
class OffhoursDigest
{
    public function __construct(
        private readonly ManagerAbsenceResolver $absences,
        private readonly WorkingCalendar $calendar,
        private readonly PickupQueue $queue,
    ) {}

    /** Окно «пока вас не было»: от 18:00 прошлого рабочего дня офиса до момента рассылки. */
    public function window(CarbonInterface $now): array
    {
        $cursor = Carbon::parse($now)->subDay();
        for ($i = 0; $i < 14 && ! $this->calendar->isWorkingDay($cursor); $i++) {
            $cursor->subDay();
        }

        return [$cursor->copy()->setTime(18, 0), Carbon::parse($now)];
    }

    /**
     * @return list<array{recipient: User, on_behalf_of: ?PersonalManager, sections: array<string, array<int, array<string, mixed>>>, total: int}>
     */
    public function build(CarbonInterface $now, bool $withUnassigned = false): array
    {
        [$from, $to] = $this->window($now);
        $events = collect();

        // Отправлено на склад: обычные заказы, созданные в окне, и подтверждённые резервы.
        Order::query()
            ->where('type', OrderType::ORDER->value)
            ->where('reserve', false)
            ->where(fn ($q) => $q->whereBetween('created_at', [$from, $to])
                ->orWhere(fn ($r) => $r->where('reserve_outcome', 'confirmed')->whereBetween('updated_at', [$from, $to])))
            ->get()
            ->each(fn (Order $o) => $events->push(['sent', $o, $o->reserve_outcome === 'confirmed' ? 'резерв подтверждён' : 'новый заказ']));

        // Резервы, потерянные в окне.
        Order::withTrashed()
            ->whereIn('reserve_outcome', ['expired', 'cancelled'])
            ->whereBetween('updated_at', [$from, $to])
            ->get()
            ->each(fn (Order $o) => $events->push(['reserve_lost', $o, $o->reserve_outcome === 'expired' ? 'резерв истёк' : 'клиент отменил']));

        // Собрано и выдано в окне.
        $readyIssueIds = GoodsIssueStatusHistory::query()
            ->where('to_status', GoodsIssue::STATUS_SHIPPED)
            ->whereBetween('changed_at', [$from, $to])
            ->pluck('goods_issue_id')->unique();
        $handovers = PickupHandover::query()->active()
            ->where('method', '!=', PickupHandover::METHOD_BACKFILL)
            ->whereBetween('issued_at', [$from, $to])->get()->keyBy('goods_issue_id');
        $review = PickupHandover::query()->active()->where('needs_review', true)->get()->keyBy('goods_issue_id');
        $waiting = $this->queue->awaiting()->concat($this->queue->stale())->keyBy('id');

        $issueIds = $readyIssueIds->merge($handovers->keys())->merge($review->keys())->merge($waiting->keys())->unique();
        foreach ($this->pickupOrdersByIssue($issueIds) as $issueId => $orders) {
            foreach ($orders as $order) {
                if ($handovers->has($issueId)) {
                    $h = $handovers[$issueId];
                    $events->push(['handed', $order, 'выдан '.$h->issued_at->format('d.m H:i').($h->recipient_name ? ', курьер '.$h->recipient_name : '')]);
                } elseif ($waiting->has($issueId)) {
                    $events->push(['not_picked', $order, 'собран, ждёт с '.Carbon::parse($waiting[$issueId]['waiting_since'])->format('d.m H:i')]);
                } elseif ($readyIssueIds->contains($issueId)) {
                    $events->push(['ready', $order, 'собран']);
                }

                if ($review->has($issueId)) {
                    $events->push(['review', $order, (string) $review[$issueId]->review_note]);
                }
            }
        }

        return $this->group($events, $now, $withUnassigned);
    }

    /**
     * Сводка по отделу для руководителя отдела продаж (решение заказчика 18.09.2026): те же события по всем
     * клиентам, включая ничейных, с именем менеджера в строке. Одна на всех получателей.
     *
     * @return array{sections: array<string, array<int, array<string, mixed>>>, total: int}
     */
    public function department(CarbonInterface $now): array
    {
        $sections = [];
        $total = 0;

        foreach ($this->build($now, withUnassigned: true) as $group) {
            $who = $group['manager_name'];
            foreach ($group['sections'] as $section => $rows) {
                foreach ($rows as $row) {
                    $sections[$section][] = ['note' => trim($row['note'].' · '.$who, ' ·')] + $row;
                    $total++;
                }
            }
        }

        return ['sections' => $sections, 'total' => $total];
    }

    /**
     * @param  Collection<int, int>  $issueIds
     * @return array<int, Collection<int, Order>>
     */
    private function pickupOrdersByIssue(Collection $issueIds): array
    {
        if ($issueIds->isEmpty()) {
            return [];
        }

        $links = GoodsIssueItem::query()->whereIn('goods_issue_id', $issueIds)->whereNotNull('order_uuid')
            ->select(['goods_issue_id', 'order_uuid'])->distinct()->get();
        $orders = Order::withTrashed()->whereIn('uuid', $links->pluck('order_uuid')->unique())
            ->where('delivery_method', DeliveryMethod::PICKUP->value)->get()->keyBy('uuid');

        return $links->groupBy('goods_issue_id')
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => $orders->get($row->order_uuid))->filter()->values())
            ->all();
    }

    /**
     * @param  Collection<int, array{0: string, 1: Order, 2: string}>  $events
     * @return list<array<string, mixed>>
     */
    private function group(Collection $events, CarbonInterface $now, bool $withUnassigned = false): array
    {
        $clients = User::query()->whereIn('id', $events->map(fn ($e) => $e[1]->user_id)->filter()->unique())
            ->get(['id', 'name', 'erp_name', 'personal_manager_id'])->keyBy('id');
        $managers = PersonalManager::query()->whereKey($clients->pluck('personal_manager_id')->filter()->unique()->all())
            ->with('user')->get()->keyBy('id');

        $groups = [];
        foreach ($events as [$section, $order, $note]) {
            $client = $clients->get($order->user_id);
            $manager = $client ? $managers->get($client->personal_manager_id) : null;
            $effective = $manager ? $this->absences->effectiveManager($manager, $now) : null;
            $recipient = $effective?->user;
            $reachable = $recipient !== null && filled($recipient->email);

            // Менеджеру письмо шлём только если есть кому; в сводку отдела попадают и ничейные клиенты.
            if (! $reachable && (! $withUnassigned || $client === null)) {
                continue;
            }

            $groupKey = $reachable ? $recipient->id : 0;
            $groups[$groupKey] ??= [
                'recipient' => $reachable ? $recipient : null,
                'manager_name' => $reachable ? (string) $effective->name : 'без менеджера',
                'on_behalf_of' => $reachable && $effective->id !== $manager->id ? $manager : null,
                'sections' => [],
                'total' => 0,
            ];

            // Один заказ в одном разделе — одной строкой (ордеров у заказа может быть два).
            $key = $section.':'.$order->id;
            if (isset($groups[$groupKey]['seen'][$key])) {
                continue;
            }
            $groups[$groupKey]['seen'][$key] = true;
            $groups[$groupKey]['sections'][$section][] = [
                'order_id' => $order->id,
                'number' => $order->erp_number ?: $order->number,
                'client' => $client->erp_name ?: $client->name,
                'amount' => (float) $order->total_amount,
                'note' => $note,
            ];
            $groups[$groupKey]['total']++;
        }

        return array_values(array_map(function (array $group) {
            unset($group['seen']);

            return $group;
        }, $groups));
    }
}

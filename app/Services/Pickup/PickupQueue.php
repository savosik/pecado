<?php

namespace App\Services\Pickup;

use App\Enums\DeliveryMethod;
use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\Order;
use App\Models\Pickup\PickupHandover;
use App\Services\Warehouse\WarehouseSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Очередь выдачи на складе (pick-07): что ждёт курьера, что собирается, что выдано.
 *
 * Строится от расходного ордера, а не от реализации: реализация появляется по факту отгрузки
 * и для вопроса «что лежит на стойке выдачи» не годится. Самовывоз определяется по заказу.
 */
class PickupQueue
{
    private const LIMIT = 300;

    public function __construct(
        private readonly OrderFulfilmentResolver $resolver,
        private readonly WarehouseSchedule $schedule,
    ) {}

    /** Собраны и ждут курьера (без зависших). */
    public function awaiting(): Collection
    {
        return $this->present(
            $this->awaitingQuery()->where('status_changed_at', '>=', $this->staleBorder())->orderBy('status_changed_at')->limit(self::LIMIT)->get(),
        );
    }

    /** Ждут дольше `pickup.stale_days` — повод позвонить клиенту или закрыть хвост. */
    public function stale(): Collection
    {
        return $this->present(
            $this->awaitingQuery()->where('status_changed_at', '<', $this->staleBorder())->orderBy('status_changed_at')->limit(self::LIMIT)->get(),
        );
    }

    /** В сборке: с обещанным временем и признаком просрочки SLA. */
    public function picking(): Collection
    {
        $issues = $this->pickupIssues()
            ->whereIn('status', GoodsIssue::ACTIVE_STATUSES)
            ->where('created_at', '>=', now()->subDays(14))
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get();

        return $this->present($issues)->map(function (array $row) {
            $promised = $this->schedule->promisedReadyAt($row['_created_at']);

            return $row + [
                'promised_at' => $promised?->toIso8601String(),
                'promised_text' => $promised?->format($promised->isToday() ? 'H:i' : 'd.m H:i'),
                'is_overdue' => $promised !== null && $promised->isPast(),
            ];
        })->values();
    }

    /** Выдано сегодня (включая отменённые — чтобы было видно исправления). */
    public function issuedToday(): Collection
    {
        $handovers = PickupHandover::query()
            ->where('issued_at', '>=', now($this->schedule->timezone())->startOfDay())
            ->with(['goodsIssue', 'issuer:id,name', 'canceller:id,name', 'pass:id,code'])
            ->orderByDesc('issued_at')
            ->limit(self::LIMIT)
            ->get();

        return $this->presentHandovers($handovers);
    }

    /** Выдачи, после которых ордер откатился в 1С. */
    public function needsReview(): Collection
    {
        return $this->presentHandovers(
            PickupHandover::query()->active()->where('needs_review', true)
                ->with(['goodsIssue', 'issuer:id,name', 'pass:id,code'])
                ->orderByDesc('issued_at')->limit(self::LIMIT)->get(),
        );
    }

    /** @return array{awaiting: int, picking_overdue: int, stale: int, review: int} */
    public function counters(): array
    {
        return [
            'awaiting' => (clone $this->awaitingQuery())->where('status_changed_at', '>=', $this->staleBorder())->count(),
            'stale' => (clone $this->awaitingQuery())->where('status_changed_at', '<', $this->staleBorder())->count(),
            'picking_overdue' => $this->picking()->where('is_overdue', true)->count(),
            'review' => PickupHandover::query()->active()->where('needs_review', true)->count(),
        ];
    }

    /**
     * Ручной поиск — запасной путь без пропуска. Находит и ордера без самовывозного заказа
     * (у части ордеров заказов нет вовсе): по номеру ордера, номеру заказа, клиенту.
     */
    public function search(string $term): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 3) {
            return collect();
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $orderUuids = Order::query()
            ->where('created_at', '>=', now()->subDays(60))
            ->where(function (Builder $q) use ($like) {
                $q->where('number', 'like', $like)
                    ->orWhere('erp_number', 'like', $like)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('erp_name', 'like', $like))
                    ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', $like));
            })
            ->limit(200)
            ->pluck('uuid');

        $issues = GoodsIssue::query()
            ->where('status', GoodsIssue::STATUS_SHIPPED)
            ->where('status_changed_at', '>=', now()->subDays(60))
            ->whereDoesntHave('activeHandover')
            ->where(function (Builder $q) use ($like, $orderUuids) {
                $q->where('number', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhereHas('items', fn (Builder $i) => $i->where('order_number', 'like', $like)
                        ->when($orderUuids->isNotEmpty(), fn (Builder $b) => $b->orWhereIn('order_uuid', $orderUuids)));
            })
            ->orderByDesc('status_changed_at')
            ->limit(30)
            ->get();

        return $this->present($issues);
    }

    /**
     * Привести ордера к строкам экрана: клиент и заказы берутся через строки ордера.
     *
     * @param  Collection<int, GoodsIssue>  $issues
     * @return Collection<int, array<string, mixed>>
     */
    public function present(Collection $issues): Collection
    {
        if ($issues->isEmpty()) {
            return collect();
        }

        $links = GoodsIssueItem::query()
            ->whereIn('goods_issue_id', $issues->pluck('id'))
            ->whereNotNull('order_uuid')
            ->select(['goods_issue_id', 'order_uuid'])
            ->distinct()
            ->get()
            ->groupBy('goods_issue_id');

        $orders = Order::withoutGlobalScopes()
            ->whereIn('uuid', $links->flatten()->pluck('order_uuid')->unique())
            ->with(['user:id,name,erp_name,phone', 'company:id,name'])
            ->get(['id', 'uuid', 'number', 'erp_number', 'user_id', 'company_id', 'delivery_method'])
            ->keyBy('uuid');

        return $issues->map(function (GoodsIssue $gi) use ($links, $orders) {
            $own = ($links[$gi->id] ?? collect())->map(fn ($row) => $orders->get($row->order_uuid))->filter()->values();
            $pickup = $own->filter(fn (Order $o) => $o->delivery_method === DeliveryMethod::PICKUP);
            $lead = $pickup->first() ?? $own->first();

            return [
                'id' => $gi->id,
                'number' => $gi->number,
                'status' => $gi->status,
                'status_label' => GoodsIssue::STATUS_LABELS[$gi->status] ?? $gi->status,
                'packages_count' => (int) $gi->packages_count,
                'waiting_since' => $gi->status_changed_at?->toIso8601String(),
                'client_id' => $lead?->user_id,
                'client' => $lead?->user?->erp_name ?: ($lead?->user?->name ?: ($gi->recipient_name ?: 'Клиент не определён')),
                'client_phone' => $lead?->user?->phone,
                'company' => $lead?->company?->name,
                'orders' => $own->map(fn (Order $o) => [
                    'id' => $o->id,
                    'number' => $o->erp_number ?: $o->number,
                    'site_number' => $o->number,
                    'is_pickup' => $o->delivery_method === DeliveryMethod::PICKUP,
                ])->all(),
                'is_mixed' => $pickup->isNotEmpty() && $pickup->count() < $own->count(),
                'has_orders' => $own->isNotEmpty(),
                '_created_at' => $gi->created_at,
            ];
        })->values();
    }

    /**
     * @param  Collection<int, PickupHandover>  $handovers
     * @return Collection<int, array<string, mixed>>
     */
    private function presentHandovers(Collection $handovers): Collection
    {
        $rows = $this->present($handovers->pluck('goodsIssue')->filter()->unique('id')->values())->keyBy('id');

        return $handovers->map(fn (PickupHandover $h) => [
            'handover_id' => $h->id,
            'goods_issue' => $rows->get($h->goods_issue_id),
            'issued_at' => $h->issued_at->toIso8601String(),
            'issued_by' => $h->issuer?->name,
            'method' => $h->method,
            'method_label' => $h->method_label,
            'recipient_name' => $h->recipient_name,
            'pass_code' => $h->pass?->code,
            'box_verified' => $h->box_verified,
            'comment' => $h->comment,
            'is_cancelled' => $h->cancelled_at !== null,
            'cancelled_by' => $h->canceller?->name,
            'cancel_reason' => $h->cancel_reason,
            'can_cancel' => $h->cancelled_at === null
                && $h->issued_at->gte(now()->subHours((int) config('pickup.cancel_window_hours', 24))),
            'needs_review' => $h->needs_review,
            'review_note' => $h->review_note,
        ])->values();
    }

    /** @return Builder<GoodsIssue> Самовывозные ордера: есть хотя бы один заказ с самовывозом. */
    private function pickupIssues(): Builder
    {
        return GoodsIssue::query()->whereExists(function ($q) {
            $q->selectRaw('1')
                ->from('goods_issue_items')
                ->join('orders', 'orders.uuid', '=', 'goods_issue_items.order_uuid')
                ->whereColumn('goods_issue_items.goods_issue_id', 'goods_issues.id')
                ->where('orders.delivery_method', DeliveryMethod::PICKUP->value);
        });
    }

    /** @return Builder<GoodsIssue> */
    private function awaitingQuery(): Builder
    {
        $since = $this->resolver->handoverSince();

        return $this->pickupIssues()
            ->where('status', GoodsIssue::STATUS_SHIPPED)
            ->whereDoesntHave('activeHandover')
            ->when($since, fn (Builder $q) => $q->where('status_changed_at', '>=', $since));
    }

    private function staleBorder(): \Illuminate\Support\Carbon
    {
        return now()->subDays(max(1, (int) config('pickup.stale_days', 3)));
    }
}

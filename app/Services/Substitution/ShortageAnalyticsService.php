<?php

namespace App\Services\Substitution;

use App\Enums\Substitution\OfferStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика недоборов для руководителя отдела (ежемесячный взгляд).
 *
 * Бизнес-даты — по `erp_created_at` с фолбэком на `created_at` (правило
 * аналитики проекта: прод импортировал историю 1С задним числом, и `created_at`
 * у истории — дата импорта, а не документа).
 *
 * «Спасённая сумма» считается точно — по `orders.replacement_for_order_id`,
 * ради этого поле и заводилось: никаких эвристик по комментариям.
 */
class ShortageAnalyticsService
{
    /**
     * @param  list<int>|null  $userIds  скоуп клиентов (null — без ограничения)
     * @return array<string, mixed>
     */
    public function metrics(CarbonImmutable $from, CarbonImmutable $to, ?array $userIds = null): array
    {
        $offers = DB::table('substitution_offers')
            ->whereBetween('substitution_offers.created_at', [$from, $to])
            ->when($userIds !== null, fn ($q) => $q->whereIn('substitution_offers.user_id', $userIds));

        $totalOffers = (clone $offers)->count();
        $sentOffers = (clone $offers)->whereNotNull('sent_at')->count();
        $viewedOffers = (clone $offers)->whereNotNull('viewed_at')->count();
        $confirmedOffers = (clone $offers)->where('status', OfferStatus::CONFIRMED->value)->count();

        // Скорость реакции: медиана «оффер родился → письмо ушло», в часах.
        $reactionHours = (clone $offers)
            ->whereNotNull('sent_at')
            ->selectRaw('substitution_offers.created_at as c, substitution_offers.sent_at as s')
            ->get()
            ->map(fn ($row) => CarbonImmutable::parse($row->c)->floatDiffInHours(CarbonImmutable::parse($row->s)))
            ->sort()
            ->values();

        $medianReaction = $reactionHours->isEmpty()
            ? null
            : round($reactionHours[(int) floor(($reactionHours->count() - 1) / 2)], 1);

        // Строки: сколько отменено в заказах периода и сколько закрыто заменой.
        $cancelledLines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.cancelled', true)
            ->whereNull('orders.deleted_at')
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) BETWEEN ? AND ?', [$from, $to])
            ->when($userIds !== null, fn ($q) => $q->whereIn('orders.user_id', $userIds))
            ->count();

        $replacedLines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.replaces_order_item_id')
            ->whereNull('orders.deleted_at')
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) BETWEEN ? AND ?', [$from, $to])
            ->when($userIds !== null, fn ($q) => $q->whereIn('orders.user_id', $userIds))
            ->count();

        // Спасённая сумма: заказы-замены периода, точно по полю связи.
        $savedAmount = (float) DB::table('orders')
            ->whereNotNull('replacement_for_order_id')
            ->whereNull('deleted_at')
            ->whereRaw('COALESCE(erp_created_at, created_at) BETWEEN ? AND ?', [$from, $to])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->sum('total_amount');

        return [
            'offers_total' => $totalOffers,
            'offers_sent' => $sentOffers,
            'offers_viewed' => $viewedOffers,
            'offers_confirmed' => $confirmedOffers,
            'median_reaction_hours' => $medianReaction,
            'coverage_pct' => $totalOffers > 0 ? (int) round($sentOffers / $totalOffers * 100) : null,
            'conversion_pct' => $sentOffers > 0 ? (int) round($confirmedOffers / $sentOffers * 100) : null,
            'cancelled_lines' => $cancelledLines,
            'replaced_lines' => $replacedLines,
            'replaced_lines_pct' => $cancelledLines > 0 ? (int) round($replacedLines / $cancelledLines * 100) : null,
            'saved_amount' => $savedAmount,
        ];
    }

    /**
     * Какие слои реально принимаются клиентами — вход для тюнинга движка
     * и решения об автоотправке (фаза 3).
     *
     * @param  list<int>|null  $userIds
     * @return list<object{kind: string, offered: int, chosen: int}>
     */
    public function layerAcceptance(CarbonImmutable $from, CarbonImmutable $to, ?array $userIds = null): array
    {
        return DB::table('substitution_offer_items')
            ->join('substitution_offers', 'substitution_offers.id', '=', 'substitution_offer_items.offer_id')
            ->whereBetween('substitution_offers.created_at', [$from, $to])
            ->when($userIds !== null, fn ($q) => $q->whereIn('substitution_offers.user_id', $userIds))
            ->groupBy('substitution_offer_items.kind')
            ->selectRaw('substitution_offer_items.kind, COUNT(*) as offered, SUM(CASE WHEN substitution_offer_items.chosen = 1 THEN 1 ELSE 0 END) as chosen')
            ->orderByDesc('offered')
            ->get()
            ->map(fn ($row) => (object) [
                'kind' => (string) $row->kind,
                'offered' => (int) $row->offered,
                'chosen' => (int) $row->chosen,
            ])
            ->all();
    }

    /**
     * Разрез по менеджерам — как в остальной CRM-аналитике.
     *
     * @param  list<int>|null  $userIds
     * @return list<object{manager: string, offers: int, confirmed: int, saved: float}>
     */
    public function byManager(CarbonImmutable $from, CarbonImmutable $to, ?array $userIds = null): array
    {
        $saved = DB::table('orders')
            ->join('substitution_offers as so', function ($join) {
                $join->on('so.order_id', '=', 'orders.replacement_for_order_id')
                    ->where('so.status', OfferStatus::CONFIRMED->value);
            })
            ->whereNull('orders.deleted_at')
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) BETWEEN ? AND ?', [$from, $to])
            ->groupBy('so.manager_user_id')
            ->selectRaw('so.manager_user_id, SUM(orders.total_amount) as saved')
            ->pluck('saved', 'manager_user_id');

        return DB::table('substitution_offers')
            ->leftJoin('users as manager', 'manager.id', '=', 'substitution_offers.manager_user_id')
            ->whereBetween('substitution_offers.created_at', [$from, $to])
            ->when($userIds !== null, fn ($q) => $q->whereIn('substitution_offers.user_id', $userIds))
            ->groupBy('substitution_offers.manager_user_id', 'manager.name')
            ->selectRaw("
                substitution_offers.manager_user_id,
                COALESCE(NULLIF(manager.name, ''), 'Без менеджера') as manager_name,
                COUNT(*) as offers,
                SUM(CASE WHEN substitution_offers.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed
            ")
            ->orderByDesc('offers')
            ->get()
            ->map(fn ($row) => (object) [
                'manager' => (string) $row->manager_name,
                'offers' => (int) $row->offers,
                'confirmed' => (int) $row->confirmed,
                'saved' => (float) ($saved[$row->manager_user_id] ?? 0),
            ])
            ->all();
    }

    /**
     * Удержание: доля клиентов с недобором периода, сделавших заказ после
     * первого недобора, против доли повторных заказов у остальных клиентов.
     *
     * @param  list<int>|null  $userIds
     * @return array{shortage_clients: int, shortage_repeat_pct: int|null, other_repeat_pct: int|null}
     */
    public function retention(CarbonImmutable $from, CarbonImmutable $to, ?array $userIds = null): array
    {
        $shortageClients = DB::table('substitution_offers')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('user_id')
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->selectRaw('user_id, MIN(created_at) as first_offer_at')
            ->groupBy('user_id')
            ->get();

        $repeat = 0;

        foreach ($shortageClients as $row) {
            $hasLaterOrder = DB::table('orders')
                ->where('user_id', $row->user_id)
                ->whereNull('deleted_at')
                ->whereNull('replacement_for_order_id')
                ->whereRaw('COALESCE(erp_created_at, created_at) > ?', [$row->first_offer_at])
                ->exists();

            if ($hasLaterOrder) {
                $repeat++;
            }
        }

        // База сравнения: клиенты периода без недобора, у которых больше одного заказа.
        $otherClients = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereRaw('COALESCE(erp_created_at, created_at) BETWEEN ? AND ?', [$from, $to])
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $shortageClients->pluck('user_id')->all() ?: [0])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as orders_count')
            ->get();

        $otherRepeat = $otherClients->where('orders_count', '>', 1)->count();

        return [
            'shortage_clients' => $shortageClients->count(),
            'shortage_repeat_pct' => $shortageClients->isNotEmpty()
                ? (int) round($repeat / $shortageClients->count() * 100)
                : null,
            'other_repeat_pct' => $otherClients->isNotEmpty()
                ? (int) round($otherRepeat / $otherClients->count() * 100)
                : null,
        ];
    }

    /**
     * Топ повторных недоборов за окно — отчёт закупкам: лечит запас, а не симптом.
     *
     * @return list<object{product_id: int|null, name: string, shortages: int, lost_amount: float}>
     */
    public function repeatedShortages(int $windowDays = 90, int $minShortages = 2): array
    {
        $since = now()->subDays($windowDays)->toDateTimeString();

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.cancelled', true)
            ->whereNull('orders.deleted_at')
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) >= ?', [$since])
            ->groupBy('order_items.product_id')
            ->havingRaw('COUNT(*) >= ?', [$minShortages])
            ->selectRaw('order_items.product_id, MAX(order_items.name) as name, COUNT(*) as shortages, COALESCE(SUM(order_items.subtotal), 0) as lost_amount')
            ->orderByDesc('shortages')
            ->orderByDesc('lost_amount')
            ->get()
            ->map(fn ($row) => (object) [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'name' => (string) $row->name,
                'shortages' => (int) $row->shortages,
                'lost_amount' => (float) $row->lost_amount,
            ])
            ->all();
    }
}

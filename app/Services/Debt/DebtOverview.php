<?php

namespace App\Services\Debt;

use App\Enums\DebtLevel;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\User;
use App\Support\Debt\DebtControl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Дебиторка глазами отдела (карточка debt-05): список партнёров со ступенью,
 * бейджи для списка клиентов, заказы, заведённые в 1С клиентам в стопе.
 *
 * Читает и теневые строки: в тени раздел и есть отчёт «что бы сделала
 * система», который показывают заказчику до включения действий.
 */
class DebtOverview
{
    /**
     * @param  Builder<User>  $clients  скоуп партнёров (visibleInCrm)
     * @return array<string, mixed>
     */
    public function rows(Builder $clients, ?string $level = null, ?int $managerId = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $partners = DebtState::query()
            ->with(['user:id,name,erp_name,personal_manager_id', 'user.personalManager:id,name'])
            ->partners()
            ->whereIn('user_id', (clone $clients)->select('users.id'))
            ->where('level', '<>', DebtLevel::CLEAN->value)
            ->when($level !== null && $level !== '', fn ($query) => $query->where('level', $level))
            ->when($managerId !== null, fn ($query) => $query->whereHas('user', fn ($users) => $users->where('personal_manager_id', $managerId)))
            ->get();

        $userIds = $partners->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        $contractors = DebtState::query()
            ->with('company:id,name')
            ->contractors()
            ->whereIn('user_id', $userIds)
            ->where('overdue_amount', '>', 0)
            ->orderByDesc('overdue_amount')
            ->get()
            ->groupBy('user_id');

        $pauses = DebtPause::query()
            ->with(['author:id,name', 'company:id,name'])
            ->active(Carbon::instance($today))
            ->whereIn('user_id', $userIds)
            ->orderByDesc('until')
            ->get()
            ->groupBy('user_id');

        $erpOrders = $this->erpOrdersForRestricted($partners, $today);

        $rows = $partners
            ->map(function (DebtState $state) use ($contractors, $pauses, $erpOrders): array {
                $pause = $pauses->get($state->user_id, collect())->first();

                return [
                    ...$state->toPayload(),
                    'client' => [
                        'id' => $state->user_id,
                        'name' => $state->user?->display_name ?? ('#'.$state->user_id),
                        'manager' => $state->user?->personalManager?->name,
                    ],
                    'contractors' => $contractors->get($state->user_id, collect())->map(fn (DebtState $row): array => [
                        'company_id' => $row->company_id,
                        'company_name' => $row->company?->name ?? ('#'.$row->company_id),
                        'level' => $row->level->value,
                        'level_label' => $row->level->label(),
                        'level_color' => $row->level->color(),
                        'overdue_amount' => (float) $row->overdue_amount,
                        'age_days' => $row->age_days,
                        'oldest_due_date' => $row->oldest_due_date?->format('d.m.Y'),
                    ])->values()->all(),
                    'pause' => $pause?->toPayload(),
                    'erp_orders' => $erpOrders[$state->user_id] ?? null,
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => DebtLevel::from($b['level'])->rank() <=> DebtLevel::from($a['level'])->rank(),
                fn (array $a, array $b): int => $b['overdue_amount'] <=> $a['overdue_amount'],
            ])
            ->values();

        $byLevel = array_fill_keys(array_map(fn (DebtLevel $item): string => $item->value, DebtLevel::cases()), 0);

        foreach ($rows as $row) {
            $byLevel[$row['level']]++;
        }

        return [
            'rows' => $rows->all(),
            'totals' => [
                'partners' => $rows->count(),
                'overdue' => round((float) $rows->sum('overdue_amount'), 2),
                'by_level' => $byLevel,
                'paused' => $rows->filter(fn (array $row): bool => $row['pause'] !== null)->count(),
                'erp_orders' => count($erpOrders),
            ],
            'shadow' => DebtControl::shadow(),
            'live_actions' => DebtControl::liveActions(),
        ];
    }

    /**
     * Бейджи для списка партнёров: user_id → ступень.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{level: string, label: string, color: string, reason: ?string, dry_run: bool}>
     */
    public function levelsFor(array $userIds): array
    {
        if ($userIds === [] || ! DebtControl::enabled()) {
            return [];
        }

        return DebtState::query()
            ->partners()
            ->whereIn('user_id', $userIds)
            ->where('level', '<>', DebtLevel::CLEAN->value)
            ->get()
            ->mapWithKeys(fn (DebtState $state): array => [(int) $state->user_id => [
                'level' => $state->level->value,
                'label' => $state->level->label(),
                'color' => $state->level->color(),
                'reason' => $state->reason,
                'dry_run' => $state->dry_run,
            ]])
            ->all();
    }

    /**
     * Заказы, заведённые в 1С за 30 дней клиентам с закрытыми заказами:
     * гейт сайта менеджер обходит одной кнопкой в 1С, и РОП должен это видеть.
     *
     * @param  Collection<int, DebtState>  $partners
     * @return array<int, array{count: int, amount: float}>
     */
    private function erpOrdersForRestricted(Collection $partners, CarbonImmutable $today): array
    {
        $restricted = $partners
            ->filter(fn (DebtState $state): bool => $state->level->blocksOrders())
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($restricted === []) {
            return [];
        }

        return DB::table('orders')
            ->whereIn('user_id', $restricted)
            ->whereNull('checkout_uuid')
            ->whereNull('deleted_at')
            ->where(DB::raw('COALESCE(erp_created_at, created_at)'), '>=', $today->subDays(30)->toDateTimeString())
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as cnt, SUM(total_amount) as amount')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->user_id => [
                'count' => (int) $row->cnt,
                'amount' => round((float) $row->amount, 2),
            ]])
            ->all();
    }
}

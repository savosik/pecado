<?php

namespace App\Services\Debt;

use App\Enums\DebtLevel;
use App\Events\DebtLevelChanged;
use App\Events\DebtPauseExpired;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Crm\Finance\PaymentForecast;
use App\Support\Debt\DebtControl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Единственный писатель `debt_states` (карточка debt-02).
 *
 * Ночной пересчёт считает ступень каждой пары партнёр × контрагент по ленте
 * регистра и сводку по партнёру. Правила движения:
 *
 *  - вниз (ужесточение) — сразу до замеренной ступени, но не по устаревшему
 *    балансу 1С и не под действующей разблокировкой;
 *  - вверх (смягчение) — сразу и без ограничений; событийный `refresh()`
 *    умеет только вверх.
 *
 * Переходы в боевом режиме порождают `DebtLevelChanged` — письма и задачи
 * висят на нём. В тени (dry_run) событий нет: система пишет «я бы сделала».
 */
class DebtStateService
{
    public function __construct(
        private readonly PaymentForecast $forecast,
        private readonly DebtLadder $ladder,
    ) {}

    /**
     * Полный пересчёт (ночью) либо пересчёт по списку партнёров.
     *
     * @param  list<int>|null  $onlyUserIds  null — все партнёры с движениями
     * @return array{users: int, pairs: int, transitions: list<array<string, mixed>>, levels: array<string, int>, dry_run: bool, expired_pauses: int}
     */
    public function recalculate(
        ?CarbonImmutable $today = null,
        ?bool $dryRun = null,
        ?array $onlyUserIds = null,
        bool $upwardOnly = false,
    ): array {
        $today ??= CarbonImmutable::today();
        $dryRun ??= DebtControl::shadow();
        $cutoff = $this->ladder->graceCutoff($today);

        $snapshot = $this->snapshot($today, $cutoff, $onlyUserIds);
        $existing = $this->existingStates($onlyUserIds);

        $userIds = array_values(array_unique([
            ...array_keys($snapshot),
            ...$existing->keys()->all(),
        ]));

        if ($userIds === []) {
            return $this->report(0, 0, [], $dryRun, 0);
        }

        $debts = $this->partnerDebts($userIds);
        $stale = $this->staleUsers($userIds, $today);
        $pauses = $this->activePauses($userIds, $today);

        $transitions = [];
        $pairs = 0;

        DB::transaction(function () use (
            $snapshot, $existing, $userIds, $debts, $stale, $pauses, $today, $dryRun, $upwardOnly, &$transitions, &$pairs
        ): void {
            foreach ($userIds as $userId) {
                $result = $this->recalculateUser(
                    $userId,
                    $snapshot[$userId] ?? [],
                    $existing->get($userId, collect()),
                    (float) ($debts[$userId] ?? 0.0),
                    in_array($userId, $stale, true),
                    $pauses->get($userId, collect()),
                    $today,
                    $dryRun,
                    $upwardOnly,
                );

                $pairs += $result['pairs'];
                array_push($transitions, ...$result['transitions']);
            }
        });

        $expired = $upwardOnly ? 0 : $this->releaseExpiredPauses($today, $dryRun);

        if (! $dryRun) {
            foreach ($transitions as $transition) {
                DebtLevelChanged::dispatch($transition['state'], $transition['from'], $transition['to']);
            }
        }

        return $this->report(count($userIds), $pairs, $transitions, $dryRun, $expired);
    }

    /**
     * Событийный пересчёт после оплаты: только вверх, только по этим партнёрам.
     *
     * @param  list<int>  $userIds
     */
    public function refresh(array $userIds, ?CarbonImmutable $today = null): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($userIds === [] || ! DebtControl::enabled()) {
            return $this->report(0, 0, [], true, 0);
        }

        return $this->recalculate($today, null, $userIds, upwardOnly: true);
    }

    /**
     * Боевая сводная ступень партнёра — то, на что имеют право опираться
     * гейт и кабинет. Теневые строки не считаются.
     */
    public function partnerState(int $userId): ?DebtState
    {
        return DebtState::query()
            ->partners()
            ->live()
            ->where('user_id', $userId)
            ->first();
    }

    public function contractorState(int $userId, int $companyId): ?DebtState
    {
        return DebtState::query()
            ->live()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->first();
    }

    /**
     * Разбор «почему у клиента такая ступень»: сводка, контрагенты, разблокировки.
     *
     * @return array<string, mixed>
     */
    public function explain(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $states = DebtState::query()
            ->with('company')
            ->where('user_id', $user->getKey())
            ->orderByRaw('company_id IS NOT NULL')
            ->orderByDesc('overdue_amount')
            ->get();

        $partner = $states->first(fn (DebtState $state): bool => $state->isPartnerRow());
        $contractors = $states->filter(fn (DebtState $state): bool => ! $state->isPartnerRow())->values();

        $pauses = DebtPause::query()
            ->with(['company', 'author'])
            ->where('user_id', $user->getKey())
            ->orderByDesc('until')
            ->limit(20)
            ->get();

        return [
            'partner' => $partner?->toPayload(),
            'contractors' => $contractors->map(fn (DebtState $state): array => [
                ...$state->toPayload(),
                'company_name' => $state->company?->name,
            ])->all(),
            'pauses' => $pauses->map(fn (DebtPause $pause): array => $pause->toPayload())->all(),
            'active_pause' => $pauses->first(fn (DebtPause $pause): bool => $pause->isActive(Carbon::instance($today)))?->toPayload(),
            'thresholds' => [
                'min_overdue' => $this->ladder->minOverdue(),
                'grace_bank_days' => $this->ladder->graceBankDays(),
                'no_preorders_days' => $this->ladder->daysFor(DebtLevel::NO_PREORDERS),
                'no_orders_days' => $this->ladder->daysFor(DebtLevel::NO_ORDERS),
                'hold_days' => $this->ladder->daysFor(DebtLevel::HOLD),
                'hold_share' => $this->ladder->holdShare(),
            ],
            'shadow' => DebtControl::shadow(),
        ];
    }

    /**
     * @param  array<int, array{overdue: float, overdue_total: float, oldest: ?string, lines: int}>  $pairs  company_id → замер
     * @param  Collection<int, DebtState>  $existing  company_id (0 — партнёр) → строка
     * @param  Collection<int, DebtPause>  $pauses
     * @return array{pairs: int, transitions: list<array<string, mixed>>}
     */
    private function recalculateUser(
        int $userId,
        array $pairs,
        Collection $existing,
        float $debt,
        bool $stale,
        Collection $pauses,
        CarbonImmutable $today,
        bool $dryRun,
        bool $upwardOnly,
    ): array {
        // Контрагенты: и те, у кого просрочка есть сейчас, и те, у кого была
        // (им положен переход в clean и письмо «погашено»).
        $companyIds = array_values(array_unique([
            ...array_keys($pairs),
            ...$existing->keys()->filter(fn (int $id): bool => $id > 0)->all(),
        ]));

        $partnerOverdue = 0.0;
        $partnerOverdueTotal = 0.0;
        $partnerAge = 0;
        $partnerLines = 0;
        $partnerOldest = null;

        foreach ($companyIds as $companyId) {
            $measure = $pairs[$companyId] ?? ['overdue' => 0.0, 'overdue_total' => 0.0, 'oldest' => null, 'lines' => 0];
            $partnerOverdue += $measure['overdue'];
            $partnerOverdueTotal += $measure['overdue_total'];
            $partnerLines += $measure['lines'];

            if ($measure['oldest'] !== null && ($partnerOldest === null || $measure['oldest'] < $partnerOldest)) {
                $partnerOldest = $measure['oldest'];
            }
        }

        if ($partnerOldest !== null) {
            $partnerAge = (int) CarbonImmutable::parse($partnerOldest)->diffInDays($today);
        }

        $partnerHold = $this->ladder->holdQualifies($partnerOverdueTotal, $debt, $partnerAge, $partnerOverdue);

        $transitions = [];
        $worst = DebtLevel::CLEAN;
        $partnerPaused = $pauses->contains(fn (DebtPause $pause): bool => $pause->company_id === null);

        foreach ($companyIds as $companyId) {
            $measure = $pairs[$companyId] ?? ['overdue' => 0.0, 'overdue_total' => 0.0, 'oldest' => null, 'lines' => 0];
            $age = $measure['oldest'] === null ? 0 : (int) CarbonImmutable::parse($measure['oldest'])->diffInDays($today);
            $measured = $this->ladder->levelFor($measure['overdue'], $age, $partnerHold);

            /** @var DebtState|null $row */
            $row = $existing->get($companyId);
            $previous = $this->previousLevel($row, $dryRun);
            $paused = $partnerPaused || $pauses->contains(fn (DebtPause $pause): bool => $pause->company_id === $companyId);

            [$level, $note] = $this->resolveLevel($previous, $measured, $stale, $paused, $upwardOnly);

            $reason = $this->reason($level, $measure['overdue'], $age, $measure['oldest'], $note);

            $state = $this->persist($row, [
                'user_id' => $userId,
                'company_id' => $companyId,
                'level' => $level,
                'previous' => $previous,
                'overdue_amount' => $measure['overdue'],
                'overdue_total' => $measure['overdue_total'],
                'debt_amount' => 0,
                'oldest' => $measure['oldest'],
                'age_days' => $age,
                'lines_count' => $measure['lines'],
                'reason' => $reason,
                'is_stale' => $stale,
                'dry_run' => $dryRun,
            ], $today);

            if ($level !== $previous) {
                $transitions[] = ['state' => $state, 'from' => $previous, 'to' => $level, 'user_id' => $userId, 'company_id' => $companyId];
            }

            $worst = DebtLevel::worst($worst, $level);
        }

        // Сводная строка партнёра: худший контрагент. Событий не порождает —
        // письма и задачи адресуются контрагенту.
        /** @var DebtState|null $partnerRow */
        $partnerRow = $existing->get(0);
        $partnerPrevious = $this->previousLevel($partnerRow, $dryRun);
        $partnerNote = $partnerHold ? sprintf('просрочка %d %% долга', (int) round(100 * $partnerOverdueTotal / max($debt, 0.01))) : null;

        if ($partnerPaused) {
            $partnerNote = trim(($partnerNote ? $partnerNote.'; ' : '').'действует разблокировка');
        }

        $this->persist($partnerRow, [
            'user_id' => $userId,
            'company_id' => null,
            'level' => $worst,
            'previous' => $partnerPrevious,
            'overdue_amount' => $partnerOverdue,
            'overdue_total' => $partnerOverdueTotal,
            'debt_amount' => $debt,
            'oldest' => $partnerOldest,
            'age_days' => $partnerAge,
            'lines_count' => $partnerLines,
            'reason' => $this->reason($worst, $partnerOverdue, $partnerAge, $partnerOldest, $partnerNote),
            'is_stale' => $stale,
            'dry_run' => $dryRun,
        ], $today);

        return ['pairs' => count($companyIds), 'transitions' => $transitions];
    }

    /**
     * Откуда двигаемся. Теневые строки для боевого запуска — как чистые:
     * так первый боевой пересчёт идёт по ступеням с письмом на каждой,
     * а не ставит гейт без единого предупреждения.
     */
    private function previousLevel(?DebtState $row, bool $dryRun): DebtLevel
    {
        if ($row === null) {
            return DebtLevel::CLEAN;
        }

        if ($row->dry_run && ! $dryRun) {
            return DebtLevel::CLEAN;
        }

        return $row->level;
    }

    /**
     * @return array{0: DebtLevel, 1: ?string}
     */
    private function resolveLevel(DebtLevel $previous, DebtLevel $measured, bool $stale, bool $paused, bool $upwardOnly): array
    {
        if (! $measured->isWorseThan($previous)) {
            return [$measured, null];
        }

        if ($upwardOnly) {
            return [$previous, null];
        }

        if ($stale) {
            return [$previous, 'баланс 1С устарел — ступень не ужесточается'];
        }

        if ($paused) {
            return [$previous, 'действует разблокировка'];
        }

        return [$this->ladder->stepDown($previous, $measured), null];
    }

    private function reason(DebtLevel $level, float $overdue, int $age, ?string $oldest, ?string $note): string
    {
        $text = match (true) {
            $level === DebtLevel::CLEAN && $overdue > 0 => sprintf('Просрочка %s ₽ ниже отсечки %s ₽', $this->money($overdue), $this->money($this->ladder->minOverdue())),
            $level === DebtLevel::CLEAN => 'Просрочки нет',
            default => sprintf(
                'Просрочка %s ₽, старейшая с %s (%d дн.)',
                $this->money($overdue),
                $oldest ? CarbonImmutable::parse($oldest)->format('d.m.Y') : '—',
                $age,
            ),
        };

        if ($note !== null && $note !== '') {
            $text .= '; '.$note;
        }

        return mb_substr($text, 0, 255);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function persist(?DebtState $row, array $values, CarbonImmutable $today): DebtState
    {
        $row ??= new DebtState([
            'user_id' => $values['user_id'],
            'company_id' => $values['company_id'],
        ]);

        /** @var DebtLevel $level */
        $level = $values['level'];
        /** @var DebtLevel $previous */
        $previous = $values['previous'];
        $changed = $row->exists ? ($row->level !== $level || ($row->dry_run && ! $values['dry_run'])) : $level !== DebtLevel::CLEAN;

        if ($changed) {
            $row->previous_level = $previous;
            $row->level_changed_at = Carbon::instance($today);
            $row->since = $level === DebtLevel::CLEAN ? null : $today->toDateString();
        }

        $row->level = $level;
        $row->overdue_amount = round($values['overdue_amount'], 2);
        $row->overdue_total = round($values['overdue_total'], 2);
        $row->debt_amount = round((float) $values['debt_amount'], 2);
        $row->oldest_due_date = $values['oldest'];
        $row->age_days = $values['age_days'];
        $row->lines_count = $values['lines_count'];
        $row->reason = $values['reason'];
        $row->is_stale = $values['is_stale'];
        $row->dry_run = $values['dry_run'];
        $row->computed_at = now();
        $row->save();

        return $row;
    }

    /**
     * Замер просрочки по регистру: user_id → company_id → суммы.
     * Агрегация в PHP: строки надо свести в рубли, а просроченных даже на
     * проде сотни — таблица помещается в память свободно.
     *
     * @param  list<int>|null  $onlyUserIds
     * @return array<int, array<int, array{overdue: float, overdue_total: float, oldest: ?string, lines: int}>>
     */
    private function snapshot(CarbonImmutable $today, CarbonImmutable $cutoff, ?array $onlyUserIds): array
    {
        $rows = SettlementEntry::query()
            ->overdue(Carbon::instance($today))
            ->whereNotNull('user_id')
            ->whereNotNull('company_id')
            ->when($onlyUserIds !== null, fn ($query) => $query->whereIn('user_id', $onlyUserIds))
            ->get(['user_id', 'company_id', 'date', 'amount', 'settled_amount', 'currency_code']);

        $result = [];
        $cutoffDate = $cutoff->toDateString();

        foreach ($rows as $row) {
            $remaining = $this->forecast->toRub(
                max(0.0, (float) $row->amount - (float) $row->settled_amount),
                $row->currency_code,
            );

            if ($remaining <= 0.0) {
                continue;
            }

            $userId = (int) $row->user_id;
            $companyId = (int) $row->company_id;
            $date = $row->date instanceof \DateTimeInterface ? $row->date->format('Y-m-d') : (string) $row->date;

            $cell = &$result[$userId][$companyId];
            $cell ??= ['overdue' => 0.0, 'overdue_total' => 0.0, 'oldest' => null, 'lines' => 0];
            $cell['overdue_total'] += $remaining;

            if ($date < $cutoffDate) {
                $cell['overdue'] += $remaining;
                $cell['lines']++;

                if ($cell['oldest'] === null || $date < $cell['oldest']) {
                    $cell['oldest'] = $date;
                }
            }

            unset($cell);
        }

        return $result;
    }

    /**
     * @param  list<int>|null  $onlyUserIds
     * @return Collection<int, Collection<int, DebtState>> user_id → (company_id|0 → строка)
     */
    private function existingStates(?array $onlyUserIds): Collection
    {
        return DebtState::query()
            ->when($onlyUserIds !== null, fn ($query) => $query->whereIn('user_id', $onlyUserIds))
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows): Collection => $rows->keyBy(fn (DebtState $row): int => (int) ($row->company_id ?? 0)));
    }

    /**
     * Весь долг партнёра по ленте регистра, ₽. Положительное сальдо (аванс)
     * долгом не считается.
     *
     * @param  list<int>  $userIds
     * @return array<int, float>
     */
    private function partnerDebts(array $userIds): array
    {
        return DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(COALESCE(amount_rub, amount)) as balance')
            ->pluck('balance', 'user_id')
            ->map(fn ($balance): float => round(max(0.0, -1 * (float) $balance), 2))
            ->all();
    }

    /**
     * Партнёры, чей баланс 1С старше допустимого (или отсутствует вовсе) —
     * по ним ступень не ужесточается.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function staleUsers(array $userIds, CarbonImmutable $today): array
    {
        $threshold = $today->subDays($this->ladder->staleAfterDays())->startOfDay();

        $fresh = DB::table('contractor_balances')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, MIN(balance_erp_updated_at) as oldest')
            ->get()
            ->filter(fn (object $row): bool => $row->oldest !== null && CarbonImmutable::parse($row->oldest)->greaterThanOrEqualTo($threshold))
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_diff($userIds, $fresh));
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, Collection<int, DebtPause>>
     */
    private function activePauses(array $userIds, CarbonImmutable $today): Collection
    {
        return DebtPause::query()
            ->active(Carbon::instance($today))
            ->whereIn('user_id', $userIds)
            ->get()
            ->groupBy('user_id');
    }

    /**
     * Истёкшие разблокировки закрываются сами; поставившему — задача
     * (через событие, если задачи включены).
     */
    private function releaseExpiredPauses(CarbonImmutable $today, bool $dryRun): int
    {
        $expired = DebtPause::query()
            ->whereNull('released_at')
            ->whereDate('until', '<', $today->toDateString())
            ->get();

        foreach ($expired as $pause) {
            $pause->forceFill([
                'released_at' => now(),
                'released_reason' => DebtPause::RELEASED_EXPIRED,
            ])->save();

            if (! $dryRun) {
                DebtPauseExpired::dispatch($pause);
            }
        }

        return $expired->count();
    }

    /**
     * @param  list<array<string, mixed>>  $transitions
     * @return array{users: int, pairs: int, transitions: list<array<string, mixed>>, levels: array<string, int>, dry_run: bool, expired_pauses: int}
     */
    private function report(int $users, int $pairs, array $transitions, bool $dryRun, int $expired): array
    {
        $levels = array_fill_keys(array_map(fn (DebtLevel $level): string => $level->value, DebtLevel::cases()), 0);

        foreach ($transitions as $transition) {
            $levels[$transition['to']->value]++;
        }

        return [
            'users' => $users,
            'pairs' => $pairs,
            'transitions' => array_map(static fn (array $transition): array => [
                'user_id' => $transition['user_id'],
                'company_id' => $transition['company_id'],
                'from' => $transition['from']->value,
                'to' => $transition['to']->value,
                'reason' => $transition['state']->reason,
            ], $transitions),
            'levels' => $levels,
            'dry_run' => $dryRun,
            'expired_pauses' => $expired,
        ];
    }
}

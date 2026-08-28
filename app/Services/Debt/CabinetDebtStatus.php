<?php

namespace App\Services\Debt;

use App\Enums\DebtLevel;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Support\Cabinet\CabinetFinance;
use App\Support\Debt\DebtControl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Что показать клиенту в кабинете о долге (карточка debt-03).
 *
 * «Норма невидима»: у чистого клиента без близких сроков — null, и в DOM
 * финансового статуса нет вовсе. Ступень появляется только при значимой
 * просрочке; «срок подходит» — подсказка за несколько дней до даты.
 * Ни следующей ступени, ни дат переходов клиенту не показываем.
 */
class CabinetDebtStatus
{
    public function __construct(private readonly DebtLadder $ladder) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forUser(?User $user, ?CarbonImmutable $today = null): ?array
    {
        if ($user === null || $user->isStaff() || ! DebtControl::live(DebtControl::ACTION_CABINET)) {
            return null;
        }

        $today ??= CarbonImmutable::today();
        $partner = DebtState::query()->partners()->live()->where('user_id', $user->getKey())->first();
        $level = $partner?->level ?? DebtLevel::CLEAN;
        $dueSoon = $this->dueSoon($user, $today);

        if (! $level->isVisible() && $dueSoon === null) {
            return null;
        }

        $financeEnabled = CabinetFinance::enabledFor($user);
        $pause = DebtPause::query()
            ->active(Carbon::instance($today))
            ->where('user_id', $user->getKey())
            ->orderByDesc('until')
            ->first();

        $contractors = $level->isVisible()
            ? DebtState::query()
                ->with('company')
                ->contractors()
                ->live()
                ->where('user_id', $user->getKey())
                ->where('overdue_amount', '>', 0)
                ->orderByDesc('overdue_amount')
                ->get()
                ->map(fn (DebtState $state): array => [
                    'company_id' => $state->company_id,
                    'company_name' => $state->company?->name ?? 'Контрагент',
                    'level' => $state->level->value,
                    'level_label' => $state->level->label(),
                    'overdue_amount' => (float) $state->overdue_amount,
                    'oldest_due_date' => $state->oldest_due_date?->format('d.m.Y'),
                    'age_days' => $state->age_days,
                ])
                ->values()
                ->all()
            : [];

        // Разблокировка снимает ограничение, но не скрывает просрочку.
        $effective = $pause !== null && $level->blocksPreorders() ? DebtLevel::OVERDUE : $level;

        return [
            'level' => $level->value,
            'level_label' => $level->label(),
            'color' => $level->color(),
            'hint' => $effective->clientHint(),
            'visible' => $level->isVisible(),
            'restricted' => $effective->blocksPreorders(),
            'blocks_orders' => $effective->blocksOrders(),
            'blocks_preorders' => $effective->blocksPreorders(),
            'since' => $partner?->since?->format('d.m.Y'),
            'overdue_amount' => (float) ($partner?->overdue_amount ?? 0),
            'oldest_due_date' => $partner?->oldest_due_date?->format('d.m.Y'),
            'age_days' => (int) ($partner?->age_days ?? 0),
            'contractors' => $contractors,
            'pause' => $pause === null ? null : ['until' => $pause->until->format('d.m.Y')],
            'due_soon' => $dueSoon,
            'links' => [
                'payments' => $financeEnabled ? route('cabinet.payments.index', [], false) : null,
                'reconciliation' => $financeEnabled ? route('cabinet.payments.reconciliation', [], false) : null,
                'documents' => route('cabinet.documents.index', [], false),
            ],
            // Ключ для «один тост за сессию при смене ступени».
            'key' => $level->value.':'.($partner?->since?->toDateString() ?? ''),
        ];
    }

    /**
     * Строки регистра, срок по которым наступает в ближайшие дни.
     *
     * @return array<string, mixed>|null
     */
    private function dueSoon(User $user, CarbonImmutable $today): ?array
    {
        $horizon = $this->ladder->dueSoonDays();

        if ($horizon <= 0) {
            return null;
        }

        $lines = SettlementEntry::query()
            ->with(['company', 'organization'])
            ->outstanding()
            ->where('user_id', $user->getKey())
            ->where(fn ($query) => $query->whereNull('document_kind')->orWhere('document_kind', '<>', 'order'))
            ->whereBetween('date', [$today->toDateString(), $today->addDays($horizon)->toDateString()])
            ->orderBy('date')
            ->get();

        if ($lines->isEmpty()) {
            return null;
        }

        return [
            'count' => $lines->count(),
            'amount' => round((float) $lines->sum(fn (SettlementEntry $line): float => (float) $line->unsettled_amount), 2),
            'nearest_date' => $lines->first()?->date?->format('d.m.Y'),
            'lines' => $lines->take(6)->map(fn (SettlementEntry $line): array => [
                'document' => $line->document_number ? '№ '.$line->document_number : ($line->document_label ?? 'Документ'),
                'document_date' => $line->document_date?->format('d.m.Y'),
                'due_date' => $line->date?->format('d.m.Y'),
                'amount' => (float) $line->unsettled_amount,
                'company_name' => $line->company?->name,
                'organization_name' => $line->organization?->name,
            ])->values()->all(),
        ];
    }
}

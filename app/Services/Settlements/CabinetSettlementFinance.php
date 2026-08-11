<?php

namespace App\Services\Settlements;

use App\Models\Organization;
use App\Models\SettlementEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Деньги клиента в личном кабинете на ленте регистра (v16.0.0, карточка fin-10).
 *
 * Клиент хочет знать ровно две вещи — «сколько я должен прямо сейчас» и «дошёл ли
 * мой платёж». На второй отвечает журнал платежей, он не менялся; на первый —
 * этот сервис.
 *
 * ## Почему не переиспользован счётный движок CRM
 *
 * У CRM вопрос другой: «сколько денег придёт по отделу» — там скоуп менеджера,
 * разрезы, корзины давности и сравнение периодов. Клиенту нужна одна цифра
 * и дата, и тянуть ради них аппарат с фильтрами значило бы связать два экрана,
 * которые меняются по разным причинам.
 *
 * ## Что показываем и чего не показываем
 *
 * Переплата показывается переплатой, а не «долгом −5 000 ₽»: клиент читает минус
 * как ошибку сайта и звонит менеджеру.
 *
 * Долг перед несколькими нашими юрлицами не сворачивается в одну цифру.
 * Переплата одному юрлицу **не гасит** долг другому — взаимозачёт делает 1С,
 * и показать зачёт, которого в учёте нет, значит соврать клиенту в его пользу.
 */
class CabinetSettlementFinance
{
    /**
     * Сводка для дашборда: сколько должен сейчас и когда следующий платёж.
     *
     * @return array<string, mixed>|null null — движений нет вовсе, блок не показываем
     */
    public function summary(User $user): ?array
    {
        $facts = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('user_id', $user->id)
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as balance, COUNT(*) as entries')
            ->first();

        // Блок прячем, только когда показывать нечего вовсе. Проверяем и план:
        // движений может не быть, а обязательство по графику уже висеть, и скрыть
        // его значило бы сказать клиенту «вы ничего не должны».
        $hasAnything = (int) ($facts->entries ?? 0) > 0
            || SettlementEntry::query()->plans()->where('user_id', $user->id)->exists();

        if (! $hasAnything) {
            return null;
        }

        $today = CarbonImmutable::today()->toDateString();
        $plans = $this->outstandingPlans($user);

        $due = (float) (clone $plans)->whereDate('date', '<=', $today)
            ->sum(DB::raw('amount - settled_amount'));
        $overdue = (float) (clone $plans)->whereDate('date', '<', $today)
            ->sum(DB::raw('amount - settled_amount'));
        $next = (clone $plans)->whereDate('date', '>=', $today)->min('date');

        $balance = round((float) ($facts->balance ?? 0), 2);

        return [
            // Главное число экрана: сколько внести сейчас. Считается по плану,
            // а не по сальдо, — в сальдо входят обязательства, срок которых
            // ещё не наступил, и клиент решил бы, что должен больше.
            'due_now' => round($due, 2),
            'overdue' => round($overdue, 2),
            'next_due_date' => $next !== null ? CarbonImmutable::parse($next)->format('d.m.Y') : null,
            'balance' => $balance,
            // Переплата отдельным полем, а не отрицательным долгом.
            'advance' => $balance > 0 ? $balance : 0.0,
            'organizations' => $this->balanceByOrganization($user),
        ];
    }

    /**
     * Долг перед каждым нашим юрлицом с реквизитами для оплаты.
     *
     * @return list<array<string, mixed>>
     */
    public function balanceByOrganization(User $user): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $balances = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('user_id', $user->id)
            ->whereNotNull('organization_id')
            ->groupBy('organization_id')
            ->select('organization_id')
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as balance')
            ->pluck('balance', 'organization_id');

        $overdue = $this->outstandingPlans($user)
            ->whereNotNull('organization_id')
            ->whereDate('date', '<', $today)
            ->groupBy('organization_id')
            ->select('organization_id')
            ->selectRaw('SUM(amount - settled_amount) as overdue')
            ->pluck('overdue', 'organization_id');

        if ($balances->isEmpty()) {
            return [];
        }

        return Organization::query()
            ->whereIn('id', $balances->keys())
            ->where('is_stub', false)
            ->get()
            ->map(fn (Organization $organization): array => [
                'organization_name' => $organization->name,
                'current_balance' => round((float) $balances[$organization->id], 2),
                'overdue_debt' => round((float) ($overdue[$organization->id] ?? 0), 2),
                'requisites' => array_filter([
                    'legal_name' => $organization->legal_name,
                    'tax_id' => $organization->tax_id,
                    'tax_code' => $organization->tax_code,
                    'bank_name' => $organization->bank_name,
                    'bank_bik' => $organization->bank_bik,
                    'account_number' => $organization->account_number,
                    'correspondent_account' => $organization->correspondent_account,
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * Плановые платежи месяца плюс просрочка — для календаря оплат.
     *
     * @return array{entries: list<array<string, mixed>>, overdue: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function calendar(User $user, CarbonImmutable $month): array
    {
        $today = CarbonImmutable::today();

        $monthly = $this->outstandingPlans($user)
            ->whereBetween(DB::raw('DATE(date)'), [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ])
            ->orderBy('date')
            ->get();

        // Просрочка не привязана к показываемому месяцу: клиент должен видеть её
        // всегда, в каком бы месяце календаря ни находился.
        $overdue = $this->outstandingPlans($user)
            ->whereDate('date', '<', $today->toDateString())
            ->orderBy('date')
            ->limit(200)
            ->get();

        $weekAhead = (float) $this->outstandingPlans($user)
            ->whereBetween(DB::raw('DATE(date)'), [$today->toDateString(), $today->addDays(7)->toDateString()])
            ->sum(DB::raw('amount - settled_amount'));

        return [
            'entries' => $monthly->map(fn (SettlementEntry $line): array => $this->entry($line, $today))->all(),
            'overdue' => $overdue->map(fn (SettlementEntry $line): array => $this->entry($line, $today))->all(),
            'summary' => [
                'overdue_amount' => round((float) $overdue->sum(fn (SettlementEntry $l): float => $l->unsettled_amount), 2),
                'overdue_count' => $overdue->count(),
                'week_amount' => round($weekAhead, 2),
                'month_amount' => round((float) $monthly->sum(fn (SettlementEntry $l): float => $l->unsettled_amount), 2),
            ],
        ];
    }

    /**
     * Строка календаря в той же форме, что отдаёт старый расчёт: экран один,
     * и переключение источника не должно менять его разметку.
     *
     * @return array<string, mixed>
     */
    private function entry(SettlementEntry $line, CarbonImmutable $today): array
    {
        $unpaid = $line->unsettled_amount;

        return [
            'id' => $line->getKey(),
            'due_date' => $line->date?->toDateString(),
            'due_date_label' => $line->date?->format('d.m.Y'),
            'amount' => round((float) $line->amount, 2),
            'unpaid_amount' => $unpaid,
            'is_paid' => $unpaid <= SettlementEntry::EPSILON,
            'is_overdue' => $line->is_overdue,
            'stage_name' => $line->meta['stage_name'] ?? null,
            'shipment' => [
                'id' => $line->document_id,
                'number' => $line->document_number ?? '—',
                'kind_label' => SettlementEntry::DOCUMENT_KIND_LABELS[$line->document_kind ?? ''] ?? 'Документ',
                'date_label' => $line->document_date?->format('d.m.Y'),
                // Ссылка только на реализацию: у предоплаты по заказу карточки
                // в кабинете нет, и кнопка вела бы в никуда.
                'url' => $line->document_id !== null && $line->document_kind === 'shipment'
                    ? '/cabinet/shipments/'.$line->document_id
                    : null,
            ],
        ];
    }

    /**
     * Непогашенные плановые строки клиента.
     *
     * @return \Illuminate\Database\Eloquent\Builder<SettlementEntry>
     */
    private function outstandingPlans(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return SettlementEntry::query()->outstanding()->where('user_id', $user->id);
    }
}

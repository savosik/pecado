<?php

namespace App\Services\Settlements;

use App\Models\Company;
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
     * Карточка на организацию, реквизиты в ней один раз. Когда у клиента
     * несколько собственных юрлиц, внутри карточки появляется список
     * `contractors` — платёжку выставляет конкретное юрлицо клиента, и без
     * этого списка бухгалтер не поймёт, чью оплату ждут. `due_total` и
     * `advance_total` считаются без взаимозачёта: переплата одного юрлица
     * клиента не гасит долг другого — зачёт делает только 1С.
     *
     * @return list<array<string, mixed>>
     */
    public function balanceByOrganization(User $user): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $facts = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('user_id', $user->id)
            ->whereNotNull('organization_id')
            ->groupBy('organization_id', 'company_id')
            ->select('organization_id', 'company_id')
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as balance')
            ->get();

        $overdue = $this->outstandingPlans($user)
            ->whereNotNull('organization_id')
            ->whereDate('date', '<', $today)
            ->groupBy('organization_id', 'company_id')
            ->select('organization_id', 'company_id')
            ->selectRaw('SUM(amount - settled_amount) as overdue')
            ->get();

        // Пары «организация × контрагент» собираются из фактов И просрочки:
        // плановая строка может приехать раньше первого факта, и её просрочка
        // обязана быть видимой. NULL-контрагент хранится под ключом 0.
        $pairs = [];
        foreach ($facts as $row) {
            $pairs[$row->organization_id][$row->company_id ?? 0]['balance'] = (float) $row->balance;
        }
        foreach ($overdue as $row) {
            $pairs[$row->organization_id][$row->company_id ?? 0]['overdue'] = (float) $row->overdue;
        }

        if ($pairs === []) {
            return [];
        }

        // Пока юрлицо у клиента одно, разрез — шум: карточка остаётся плоской.
        $companyIds = collect($pairs)
            ->flatMap(static fn (array $byCompany): array => array_keys($byCompany))
            ->filter()
            ->unique();
        $splitByCompany = $companyIds->count() > 1;

        // Имя нужно и удалённому контрагенту: долг переживает архивацию карточки.
        $companies = $splitByCompany
            ? Company::withTrashed()->whereIn('id', $companyIds)->pluck('name', 'id')
            : collect();

        $organizations = Organization::query()
            ->whereIn('id', array_keys($pairs))
            ->where('is_stub', false)
            ->get()
            ->keyBy('id');

        return collect($pairs)
            ->map(function (array $byCompany, int $organizationId) use ($organizations, $companies, $splitByCompany): ?array {
                $organization = $organizations->get($organizationId);

                if ($organization === null) {
                    return null;
                }

                $contractors = collect($byCompany)
                    ->map(static fn (array $sums, int $companyId): array => [
                        // Ключ пары для платёжки: диалог открывается по «контрагент × юрлицо».
                        'company_id' => $companyId !== 0 ? $companyId : null,
                        'name' => $companyId !== 0
                            ? ($companies->get($companyId) ?? 'Контрагент не указан')
                            : 'Контрагент не указан',
                        'current_balance' => round($sums['balance'] ?? 0.0, 2),
                        'overdue_debt' => round($sums['overdue'] ?? 0.0, 2),
                    ])
                    // Полностью закрытые пары не показываем — так же вёл себя
                    // старый расчёт на contractor_balances: переключение
                    // источника не должно добавлять клиенту строк «0 ₽».
                    ->filter(static fn (array $c): bool => $c['current_balance'] !== 0.0 || $c['overdue_debt'] !== 0.0)
                    ->sortBy('name')
                    ->values();

                if ($contractors->isEmpty()) {
                    return null;
                }

                return self::organizationCard($organization, $contractors, $splitByCompany);
            })
            ->filter()
            ->sortBy('organization_name')
            ->values()
            ->all();
    }

    /**
     * Карточка организации из строк её контрагентов — общая для ленты регистра
     * и старого расчёта на contractor_balances: обе ветки обязаны отдавать
     * фронту одну и ту же форму.
     *
     * @param  \Illuminate\Support\Collection<int, array{name: string, current_balance: float, overdue_debt: float}>  $contractors
     * @return array<string, mixed>
     */
    public static function organizationCard(Organization $organization, \Illuminate\Support\Collection $contractors, bool $splitByCompany): array
    {
        $balances = $contractors->pluck('current_balance');

        return [
            'organization_id' => (int) $organization->getKey(),
            // Без разреза по юрлицам контрагент один — его id нужен кнопке «Платёжка»
            // у итога организации. При разрезе кнопка стоит у каждой строки юрлица.
            'company_id' => $splitByCompany ? null : ($contractors->first()['company_id'] ?? null),
            'organization_name' => $organization->name,
            'current_balance' => round((float) $balances->sum(), 2),
            'overdue_debt' => round((float) $contractors->sum('overdue_debt'), 2),
            // Долги и авансы разных юрлиц клиента НЕ сворачиваются друг с другом.
            'due_total' => round(-(float) $balances->filter(static fn (float $b): bool => $b < 0)->sum(), 2),
            'advance_total' => round((float) $balances->filter(static fn (float $b): bool => $b > 0)->sum(), 2),
            'contractors' => $splitByCompany ? $contractors->all() : [],
            'requisites' => array_filter([
                'legal_name' => $organization->legal_name,
                'tax_id' => $organization->tax_id,
                'tax_code' => $organization->tax_code,
                'bank_name' => $organization->bank_name,
                'bank_bik' => $organization->bank_bik,
                'account_number' => $organization->account_number,
                'correspondent_account' => $organization->correspondent_account,
            ]),
        ];
    }

    /**
     * Плановые платежи месяца плюс просрочка — для календаря оплат.
     *
     * @return array{entries: list<array<string, mixed>>, overdue: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function calendar(User $user, CarbonImmutable $month, ?int $companyId = null): array
    {
        $today = CarbonImmutable::today();

        // Контрагент и наше юрлицо нужны каждой строке: клиент с несколькими
        // компаниями иначе не понимает, от кого и кому платёж.
        $plans = fn () => $this->outstandingPlans($user)
            ->with(['company:id,name', 'organization:id,name,is_stub'])
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId));

        $monthly = $plans()
            ->whereBetween(DB::raw('DATE(date)'), [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ])
            ->orderBy('date')
            ->get();

        // Просрочка не привязана к показываемому месяцу: клиент должен видеть её
        // всегда, в каком бы месяце календаря ни находился.
        $overdue = $plans()
            ->whereDate('date', '<', $today->toDateString())
            ->orderBy('date')
            ->limit(200)
            ->get();

        $weekAhead = (float) $plans()
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
            // Пара «контрагент × наше юрлицо» — подпись строки и ключ платёжки
            // (PaymentOrderService::options группирует по той же паре).
            'company_id' => $line->company_id,
            'organization_id' => $line->organization_id,
            'company' => $line->company?->name,
            // Заглушку организации клиенту не показываем — как в документах.
            'organization' => $line->organization !== null && ! $line->organization->is_stub
                ? $line->organization->name
                : null,
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
     * Контрагенты клиента, встречающиеся в ленте, — для фильтра календаря.
     *
     * Без разбора nature: плановая строка может приехать раньше первого факта,
     * и её контрагент обязан быть выбираемым — иначе фильтр спрячет строку,
     * которую календарь показывает.
     *
     * @return list<array{id: int, name: string}>
     */
    public function companiesOf(User $user): array
    {
        return SettlementEntry::query()
            ->where('settlement_entries.user_id', $user->id)
            ->whereNotNull('settlement_entries.company_id')
            ->join('companies as c', 'c.id', '=', 'settlement_entries.company_id')
            // Join в обход модели, поэтому SoftDeletes отфильтровываем руками.
            ->whereNull('c.deleted_at')
            ->distinct()
            ->orderBy('c.name')
            ->pluck('c.name', 'c.id')
            ->map(static fn (string $name, int $id): array => ['id' => $id, 'name' => $name])
            ->values()
            ->all();
    }

    /**
     * Непогашенные плановые строки клиента.
     *
     * @return \Illuminate\Database\Eloquent\Builder<SettlementEntry>
     */
    /**
     * Непогашенные планы клиента — без планов заказов.
     *
     * График заказа — план платежа, а не долг: долг создаёт отгрузка (круг 12,
     * v16.7.0). Регистр по срокам заказы не ведёт, погашение по ним не публикуется,
     * поэтому после отгрузки план заказа и план реализации лежат на одну дату
     * рядом и складываются — клиент видел «к оплате» вдвое больше долга и чуть
     * не платил дважды (ИП Дорофеева, 27.08.2026). Отсюда заказы исключены из
     * всех клиентских денег: календаря, «на неделю», «к оплате сейчас» и
     * ближайшей даты — то же правило, что у календаря CRM. NULL-ветка отдельно:
     * голое `<> 'order'` молча спрятало бы строки без document_kind.
     *
     * @return \Illuminate\Database\Eloquent\Builder<SettlementEntry>
     */
    private function outstandingPlans(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return SettlementEntry::query()
            ->outstanding()
            ->where('user_id', $user->id)
            ->where(static function ($inner): void {
                $inner->whereNull('document_kind')->orWhere('document_kind', '<>', 'order');
            });
    }
}

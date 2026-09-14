<?php

namespace App\Services\Crm\TaxRegime;

use App\Enums\Crm\CrmScope;
use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\TaxRegimeFreshness;
use App\Enums\Crm\TaxRegimeShift;
use App\Models\Company;
use App\Models\CrmContractorTaxRegime;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Реестр налоговых режимов: кто на чём сейчас и что планирует — по юрлицам в скоупе.
 *
 * Рабочий инструмент РОПа: видно, сколько ответов собрано и у кого из менеджеров
 * хвост, а выгрузка — сырьё для отчёта о рисках перехода клиентов на НДС.
 * Границу видимости задаёт Company::scopedInCrm(); юрлица без партнёра не
 * показываются — спросить про них некого.
 *
 * Фильтры — массив из {@see \App\Http\Controllers\Crm\TaxRegimeController::filters()}.
 */
class TaxRegimeRegistry
{
    public function __construct(
        private readonly ContractorTaxRegimeService $regimes,
        private readonly TaxRegimeQuery $query,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, CrmScope $scope, array $filters): LengthAwarePaginator
    {
        return $this->ordered($this->filtered($actor, $scope, $filters))
            ->with($this->eager())
            ->paginate((int) $filters['per_page'])
            ->withQueryString()
            ->through(fn (Company $company): array => $this->row($company));
    }

    /**
     * Все строки отбора — для выгрузки.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(User $actor, CrmScope $scope, array $filters): array
    {
        return $this->ordered($this->filtered($actor, $scope, $filters))
            ->with($this->eager())
            ->get()
            ->map(fn (Company $company): array => $this->row($company))
            ->values()
            ->all();
    }

    /**
     * Сводка по базе реестра — без отборов по состоянию ответа и смене режима:
     * плитки должны показывать всю картину, а не повторять выбранный фильтр.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(User $actor, CrmScope $scope, array $filters, bool $byManager): array
    {
        $companies = $this->base($actor, $scope, $filters)
            ->with(['taxRegime', 'user:id,personal_manager_id', 'user.personalManager:id,name'])
            ->get(['companies.id', 'companies.user_id']);

        $year = CrmContractorTaxRegime::targetYear();

        $shifts = $companies
            ->map(fn (Company $company): ?TaxRegimeShift => $company->taxRegime !== null
                && $company->taxRegime->planned_year !== null
                && $company->taxRegime->planned_year >= $year
                    ? TaxRegimeShift::between($company->taxRegime->current_regime, $company->taxRegime->planned_regime)
                    : null)
            ->filter()
            ->countBy(fn (TaxRegimeShift $shift): string => $shift->value);

        return [
            'target_year' => $year,
            'totals' => $this->countStates($companies),
            'shifts' => array_map(static fn (TaxRegimeShift $shift): array => [
                'value' => $shift->value,
                'label' => $shift->label(),
                'color' => $shift->color(),
                'count' => (int) $shifts->get($shift->value, 0),
            ], TaxRegimeShift::cases()),
            'managers' => $byManager
                ? $companies
                    ->groupBy(fn (Company $company): int => (int) ($company->user->personal_manager_id ?? 0))
                    ->map(fn (Collection $group): array => [
                        'name' => $group->first()?->user?->personalManager->name ?? 'Без менеджера',
                    ] + $this->countStates($group))
                    ->sortByDesc('total')
                    ->values()
                    ->all()
                : null,
        ];
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return array<string, int>
     */
    private function countStates(Collection $companies): array
    {
        $counts = $companies->countBy(
            fn (Company $company): string => CrmContractorTaxRegime::freshnessOf($company->taxRegime)->value,
        );

        $totals = ['total' => $companies->count()];

        foreach (TaxRegimeFreshness::cases() as $state) {
            $totals[$state->value] = (int) $counts->get($state->value, 0);
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Company>
     */
    private function base(User $actor, CrmScope $scope, array $filters): Builder
    {
        $query = Company::query()
            ->scopedInCrm($actor, $scope)
            ->whereNotNull('companies.user_id');

        if ($filters['active']) {
            $this->query->whereActive($query);
        }

        if ($filters['search'] !== null) {
            $like = '%'.$filters['search'].'%';

            $query->where(fn (Builder $inner) => $inner
                ->where('companies.name', 'like', $like)
                ->orWhere('companies.legal_name', 'like', $like)
                ->orWhere('companies.tax_id', 'like', $like)
                ->orWhereHas('user', fn (Builder $partner) => $partner
                    ->where('users.erp_name', 'like', $like)
                    ->orWhere('users.name', 'like', $like)));
        }

        if ($filters['manager_id'] !== null) {
            $query->whereHas('user', fn (Builder $partner) => $partner
                ->where('users.personal_manager_id', $filters['manager_id']));
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Company>
     */
    private function filtered(User $actor, CrmScope $scope, array $filters): Builder
    {
        $query = $this->base($actor, $scope, $filters);

        if ($filters['freshness'] !== null) {
            $this->query->whereFreshness($query, TaxRegimeFreshness::from($filters['freshness']));
        }

        if ($filters['shift'] !== null) {
            $this->query->whereShift($query, [TaxRegimeShift::from($filters['shift'])]);
        }

        if ($filters['current_regime'] !== null) {
            $query->whereHas('taxRegime', fn (Builder $regime) => $regime
                ->where('current_regime', TaxRegime::from($filters['current_regime'])->value));
        }

        if ($filters['planned_regime'] !== null) {
            $query->whereHas('taxRegime', fn (Builder $regime) => $regime
                ->where('planned_regime', TaxRegime::from($filters['planned_regime'])->value)
                ->where('planned_year', '>=', CrmContractorTaxRegime::targetYear()));
        }

        return $query;
    }

    /**
     * Юрлица одного партнёра — рядом, партнёры по рабочему наименованию.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderBy(DB::table('users')
                ->selectRaw('COALESCE(users.erp_name, users.name)')
                ->whereColumn('users.id', 'companies.user_id'))
            ->orderBy('companies.name')
            ->orderBy('companies.id');
    }

    /**
     * @return list<string>
     */
    private function eager(): array
    {
        return [
            'user:id,name,erp_name,personal_manager_id',
            'user.personalManager:id,name',
            'taxRegime.confirmer:id,name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Company $company): array
    {
        return [
            'id' => (int) $company->getKey(),
            'name' => (string) ($company->name ?: $company->legal_name ?: 'Контрагент №'.$company->getKey()),
            'legal_name' => $company->legal_name,
            'tax_id' => $company->tax_id,
            'tax_code' => $company->tax_code ?: null,
            'partner' => $company->user instanceof User ? [
                'id' => (int) $company->user->getKey(),
                'name' => (string) $company->user->display_name,
            ] : null,
            'manager' => $company->user?->personalManager?->name,
            'tax_regime' => $this->regimes->payload($company->taxRegime),
        ];
    }
}

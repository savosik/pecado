<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskStatus;
use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Рабочий список контрагентов — юрлиц, от имени которых партнёры покупают.
 *
 * Отдельный раздел, а не вкладка в карточке партнёра: сверка, доверенности и
 * реквизиты обсуждаются по юрлицу, а у крупного партнёра их несколько. Планы и их
 * выполнение сюда не приходят принципиально — они считаются по партнёру
 * (см. {@see ClientPlanFactService}), и колонка «план» на юрлице означала бы
 * вторую, несогласованную методику.
 *
 * Граница видимости одна — Company::visibleInCrm(): менеджер видит юрлица только
 * своих партнёров. Ни один фильтр её не расширяет.
 */
class ContractorListService
{
    /**
     * Значения фильтра «состояние долга».
     */
    public const DEBT_OVERDUE = 'overdue';

    public const DEBT_ANY = 'debt';

    /**
     * Страница списка, собранная в форму строки таблицы.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $paginator = $this->query($actor, $filters)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return $paginator->through(fn (Company $company): array => $this->row($company));
    }

    /**
     * Запрос контрагентов с применёнными фильтрами.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Company>
     */
    public function query(User $actor, array $filters): Builder
    {
        $query = Company::query()
            ->visibleInCrm($actor)
            ->with(['user:id,name,erp_name,email', 'contractorBalance'])
            ->withCount([
                'crmTasks as open_tasks_count' => fn (Builder $tasks) => $tasks
                    ->whereIn('status', TaskStatus::activeValues()),
                'crmComments as comments_count',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('legal_name', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")
                ->orWhere('registration_number', 'like', "%{$search}%"));
        }

        if (! empty($filters['client_id'])) {
            $query->where('companies.user_id', (int) $filters['client_id']);
        }

        // Отбор по менеджеру — через партнёров: у самого юрлица менеджера нет.
        if (! empty($filters['manager_id'])) {
            $query->whereIn(
                'companies.user_id',
                User::query()
                    ->where('personal_manager_id', (int) $filters['manager_id'])
                    ->select('users.id'),
            );
        }

        // «Без партнёра» — юрлица, которые 1С прислала раньше привязки. Фильтр
        // виден только тем, кто видит отдел целиком: у менеджера такие в скоуп
        // и так не попадают.
        if (! empty($filters['orphans'])) {
            $query->whereNull('companies.user_id');
        }

        $this->applyDebt($query, $filters['debt'] ?? null);
        $this->applySort($query, (string) ($filters['sort_by'] ?? 'name'), (string) ($filters['sort_order'] ?? 'asc'));

        return $query;
    }

    /**
     * Отбор по долгу. Считаем по балансам из 1С — единственный источник долга
     * (сумма неоплаченных документов даёт другую цифру, см. FinanceController).
     *
     * @param  Builder<Company>  $query
     */
    private function applyDebt(Builder $query, mixed $debt): void
    {
        if ($debt === self::DEBT_OVERDUE) {
            $query->whereHas('contractorBalance', fn (Builder $balance) => $balance->where('overdue_debt', '>', 0));

            return;
        }

        if ($debt === self::DEBT_ANY) {
            $query->whereHas('contractorBalance', fn (Builder $balance) => $balance->where('current_balance', '<>', 0));
        }
    }

    /**
     * Сортировка. По долгу и просрочке — через подзапрос к балансам: join раздул бы
     * строки контрагентам с несколькими записями баланса.
     *
     * @param  Builder<Company>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'overdue' => $query->orderBy(
                ContractorBalance::query()
                    ->select('overdue_debt')
                    ->whereColumn('contractor_balances.company_id', 'companies.id')
                    ->limit(1),
                $direction,
            ),
            'balance' => $query->orderBy(
                ContractorBalance::query()
                    ->select('current_balance')
                    ->whereColumn('contractor_balances.company_id', 'companies.id')
                    ->limit(1),
                $direction,
            ),
            'tasks' => $query->orderBy('open_tasks_count', $direction),
            default => $query->orderBy('companies.name', $direction),
        };

        // Вторичный ключ — id: без него страницы «плывут» на равных значениях.
        $query->orderBy('companies.id');
    }

    /**
     * Юрлица одного партнёра — для вкладки в его карточке.
     *
     * Скоуп здесь не нужен: партнёра карточка уже отрезолвила через
     * User::visibleInCrm(), а все его юрлица по определению попадают в тот же скоуп.
     *
     * @return list<array<string, mixed>>
     */
    public function forPartner(User $partner): array
    {
        return Company::query()
            ->where('companies.user_id', $partner->getKey())
            ->with(['user:id,name,erp_name', 'contractorBalance'])
            ->withCount([
                'crmTasks as open_tasks_count' => fn (Builder $tasks) => $tasks
                    ->whereIn('status', TaskStatus::activeValues()),
                'crmComments as comments_count',
            ])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company): array => $this->row($company))
            ->all();
    }

    /**
     * Строка таблицы.
     *
     * @return array<string, mixed>
     */
    public function row(Company $company): array
    {
        $balance = $company->contractorBalance;

        return [
            'id' => (int) $company->getKey(),
            'name' => (string) ($company->name ?: $company->legal_name ?: 'Контрагент №'.$company->getKey()),
            'legal_name' => $company->legal_name,
            'tax_id' => $company->tax_id,
            'tax_code' => $company->tax_code ?: null,
            'is_default' => (bool) $company->is_default,
            'partner' => $company->user instanceof User ? [
                'id' => (int) $company->user->getKey(),
                'name' => (string) $company->user->display_name,
            ] : null,
            'balance' => $balance === null ? null : (float) $balance->current_balance,
            'overdue_debt' => $balance === null ? null : (float) $balance->overdue_debt,
            'balance_updated_at' => $balance?->balance_erp_updated_at?->format('d.m.Y H:i'),
            'open_tasks_count' => (int) ($company->open_tasks_count ?? 0),
            'comments_count' => (int) ($company->comments_count ?? 0),
        ];
    }
}

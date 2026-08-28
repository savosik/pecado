<?php

namespace App\Services\Crm;

use App\Enums\Crm\ContractStatus;
use App\Enums\Crm\CrmScope;
use App\Enums\Crm\TaskStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCategory;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Список договоров реестра.
 *
 * Строка — договор; у одного контрагента их может быть несколько (старый
 * расторгнут, новый подписан), и это нормально: вкладку «Без договора»
 * закрывает любой действующий.
 *
 * Границу видимости задаёт Contract::scopedInCrm(); ни один фильтр её не расширяет.
 */
class ContractListService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->query($actor, $filters)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString()
            ->through(fn (Contract $contract): array => $this->row($contract));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Contract>
     */
    public function query(User $actor, array $filters): Builder
    {
        $scope = $filters['scope'] instanceof CrmScope ? $filters['scope'] : CrmScope::DEPARTMENT;

        $query = Contract::query()
            ->scopedInCrm($actor, $scope)
            ->with([
                'category:id,name',
                'organization:id,name',
                'user:id,name,erp_name',
                'company:id,user_id,name,legal_name,tax_id',
                'responsibleManager:id,name',
            ])
            ->withCount([
                'crmTasks as open_tasks_count' => fn (Builder $tasks) => $tasks
                    ->whereIn('status', TaskStatus::activeValues()),
                'media as files_count' => fn (Builder $media) => $media
                    ->where('collection_name', \App\Support\Crm\CrmAttachments::COLLECTION),
            ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, (string) ($filters['sort_by'] ?? 'date'), (string) ($filters['sort_order'] ?? 'desc'));

        return $query;
    }

    /**
     * @param  Builder<Contract>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(fn (Builder $inner) => $inner
                ->where('contracts.number', 'like', "%{$search}%")
                ->orWhere('contracts.counterparty_name', 'like', "%{$search}%")
                ->orWhere('contracts.comment', 'like', "%{$search}%")
                ->orWhereHas('company', fn (Builder $company) => $company
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%"))
                ->orWhereHas('user', fn (Builder $user) => $user
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('erp_name', 'like', "%{$search}%")));
        }

        if (! empty($filters['category_id'])) {
            $query->where('contracts.category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('contracts.status', (string) $filters['status']);
        }

        if (! empty($filters['payment_terms'])) {
            $query->where('contracts.payment_terms', (string) $filters['payment_terms']);
        }

        if (! empty($filters['form'])) {
            $query->where('contracts.form', (string) $filters['form']);
        }

        if (! empty($filters['manager_id'])) {
            $query->where('contracts.responsible_manager_id', (int) $filters['manager_id']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('contracts.user_id', (int) $filters['client_id']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('contracts.company_id', (int) $filters['company_id']);
        }

        // «Истекают» — срок кончается в ближайшие N дней или уже кончился, а договор
        // всё ещё не расторгнут: именно такие надо пролонгировать.
        if (! empty($filters['expiring'])) {
            $query
                ->whereNotNull('contracts.valid_until')
                ->where('contracts.valid_until', '<=', Carbon::today()->addDays(self::expiringDays()))
                ->where('contracts.status', '<>', ContractStatus::TERMINATED->value);
        }
    }

    /**
     * @param  Builder<Contract>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'number' => $query->orderBy('contracts.number', $direction),
            'counterparty' => $query->orderBy('contracts.counterparty_name', $direction),
            'status' => $query->orderBy('contracts.status', $direction),
            'valid_until' => $query->orderBy('contracts.valid_until', $direction),
            'signed_at' => $query->orderBy('contracts.signed_at', $direction),
            default => $query->orderBy('contracts.date', $direction),
        };

        // Вторичный ключ — id: без него страницы «плывут» на равных датах.
        $query->orderBy('contracts.id', $direction);
    }

    /**
     * Договоры одного партнёра — для вкладки в его карточке.
     *
     * Скоуп не нужен: партнёра карточка уже отрезолвила через User::visibleInCrm().
     *
     * @return list<array<string, mixed>>
     */
    public function forPartner(User $partner): array
    {
        return Contract::query()
            ->where('contracts.user_id', $partner->getKey())
            ->with(['category:id,name', 'organization:id,name', 'user:id,name,erp_name', 'company:id,user_id,name,legal_name,tax_id', 'responsibleManager:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contract $contract): array => $this->row($contract))
            ->all();
    }

    /**
     * Договоры одного юрлица — для карточки контрагента.
     *
     * @return list<array<string, mixed>>
     */
    public function forContractor(Company $company): array
    {
        return Contract::query()
            ->where('contracts.company_id', $company->getKey())
            ->with(['category:id,name', 'organization:id,name', 'user:id,name,erp_name', 'company:id,user_id,name,legal_name,tax_id', 'responsibleManager:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contract $contract): array => $this->row($contract))
            ->all();
    }

    /**
     * Категории с числом договоров в скоупе актора — шапка вкладок.
     *
     * @return list<array<string, mixed>>
     */
    public function categories(User $actor, CrmScope $scope, bool $withInactive = false): array
    {
        $counts = Contract::query()
            ->scopedInCrm($actor, $scope)
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id');

        return ContractCategory::query()
            ->when(! $withInactive, fn (Builder $query) => $query->active())
            ->with('organization:id,name')
            ->ordered()
            ->get()
            ->map(fn (ContractCategory $category): array => [
                'id' => (int) $category->getKey(),
                'name' => $category->name,
                'description' => $category->description,
                'organization_id' => $category->organization_id,
                'organization' => $category->organization?->name,
                'sort_order' => (int) $category->sort_order,
                'is_active' => (bool) $category->is_active,
                'contracts_count' => (int) ($counts[$category->getKey()] ?? 0),
            ])
            ->all();
    }

    /**
     * Строка таблицы.
     *
     * @return array<string, mixed>
     */
    public function row(Contract $contract): array
    {
        $expiring = $contract->valid_until !== null
            && $contract->status->isActive()
            && $contract->valid_until->lte(Carbon::today()->addDays(self::expiringDays()));

        return [
            'id' => (int) $contract->getKey(),
            'number' => $contract->number,
            'date' => $contract->date?->format('d.m.Y'),
            'date_iso' => $contract->date?->toDateString(),
            'signed_at' => $contract->signed_at?->format('d.m.Y'),
            'signed_at_iso' => $contract->signed_at?->toDateString(),
            'valid_from' => $contract->valid_from?->format('d.m.Y'),
            'valid_from_iso' => $contract->valid_from?->toDateString(),
            'valid_until' => $contract->valid_until?->format('d.m.Y'),
            'valid_until_iso' => $contract->valid_until?->toDateString(),
            'is_expired' => $contract->is_expired && $contract->status->isActive(),
            'is_expiring' => $expiring,
            'status' => $contract->status->value,
            'status_label' => $contract->status->label(),
            'status_color' => $contract->status->color(),
            'payment_terms' => $contract->payment_terms?->value,
            'payment_terms_label' => $contract->payment_terms?->label(),
            'payment_terms_color' => $contract->payment_terms?->color(),
            'form' => $contract->form?->value,
            'form_label' => $contract->form?->label(),
            'form_color' => $contract->form?->color(),
            'category' => [
                'id' => (int) $contract->category->getKey(),
                'name' => $contract->category->name,
            ],
            'organization' => $contract->organization === null ? null : [
                'id' => (int) $contract->organization->getKey(),
                'name' => $contract->organization->name,
            ],
            'counterparty_name' => $contract->counterparty_label,
            'company' => $contract->company instanceof Company ? [
                'id' => (int) $contract->company->getKey(),
                'name' => (string) ($contract->company->name ?: $contract->company->legal_name),
                'tax_id' => $contract->company->tax_id,
            ] : null,
            'partner' => $contract->user instanceof User ? [
                'id' => (int) $contract->user->getKey(),
                'name' => (string) $contract->user->display_name,
            ] : null,
            'manager' => $contract->responsibleManager instanceof PersonalManager ? [
                'id' => (int) $contract->responsibleManager->getKey(),
                'name' => $contract->responsibleManager->name,
            ] : null,
            'is_visible_in_cabinet' => (bool) $contract->is_visible_in_cabinet,
            'comment' => $contract->comment,
            'open_tasks_count' => (int) ($contract->getAttribute('open_tasks_count') ?? 0),
            'files_count' => (int) ($contract->getAttribute('files_count') ?? 0),
            'updated_at' => $contract->updated_at?->format('d.m.Y H:i'),
        ];
    }

    public static function expiringDays(): int
    {
        return max(1, (int) config('contracts.expiring_days', 30));
    }
}

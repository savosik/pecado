<?php

namespace App\Services\Crm;

use App\Enums\Crm\ContractStatus;
use App\Enums\Crm\CrmScope;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Вкладка «Без договора»: контрагенты, на которых проведена реализация или
 * хотя бы заказ, а действующего договора в реестре нет.
 *
 * Считается по контрагенту (юрлицу), а не по партнёру: договор подписывается
 * с юрлицом, и у партнёра с тремя юрлицами два могут быть без договора.
 *
 * Расторгнутый договор контрагента не закрывает — он снова попадает сюда.
 * Заказ — не долг, но повод: если клиент заказал, договор пора подписывать,
 * не дожидаясь реализации.
 */
class ContractGapService
{
    public const KIND_SHIPMENTS = 'shipments';

    public const KIND_ORDERS = 'orders';

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->query($actor, $filters)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString()
            ->through(fn (Company $company): array => $this->row($company));
    }

    /**
     * Сколько контрагентов без договора видит актор — бейдж на вкладке.
     */
    public function count(User $actor, CrmScope $scope): int
    {
        return $this->query($actor, ['scope' => $scope])->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Company>
     */
    public function query(User $actor, array $filters): Builder
    {
        $scope = $filters['scope'] instanceof CrmScope ? $filters['scope'] : CrmScope::DEPARTMENT;
        $kind = (string) ($filters['kind'] ?? '');

        $query = Company::query()
            ->scopedInCrm($actor, $scope)
            ->whereDoesntHave('contracts', fn (Builder $contracts) => $contracts
                ->where('status', '<>', ContractStatus::TERMINATED->value))
            ->with(['user:id,name,erp_name,personal_manager_id', 'user.personalManager:id,name'])
            ->withCount(['shipments', 'orders'])
            ->withSum('shipments', 'total_amount')
            ->withMax('shipments', 'date')
            ->withMax('orders', 'erp_created_at');

        // Отбор по признаку: с реализацией — обязательный договор, только заказ — повод.
        match ($kind) {
            self::KIND_SHIPMENTS => $query->whereHas('shipments'),
            self::KIND_ORDERS => $query->whereDoesntHave('shipments')->whereHas('orders'),
            default => $query->where(fn (Builder $inner) => $inner
                ->whereHas('shipments')
                ->orWhereHas('orders')),
        };

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(fn (Builder $inner) => $inner
                ->where('companies.name', 'like', "%{$search}%")
                ->orWhere('companies.legal_name', 'like', "%{$search}%")
                ->orWhere('companies.tax_id', 'like', "%{$search}%")
                ->orWhereHas('user', fn (Builder $user) => $user
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('erp_name', 'like', "%{$search}%")));
        }

        if (! empty($filters['manager_id'])) {
            $query->whereIn(
                'companies.user_id',
                User::query()->where('personal_manager_id', (int) $filters['manager_id'])->select('users.id'),
            );
        }

        $this->applySort($query, (string) ($filters['sort_by'] ?? 'shipments_total'), (string) ($filters['sort_order'] ?? 'desc'));

        return $query;
    }

    /**
     * @param  Builder<Company>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'name' => $query->orderBy('companies.name', $direction),
            'shipments_count' => $query->orderBy('shipments_count', $direction),
            'last_shipment' => $query->orderBy('shipments_max_date', $direction),
            'orders_count' => $query->orderBy('orders_count', $direction),
            default => $query->orderBy('shipments_sum_total_amount', $direction),
        };

        $query->orderBy('companies.id');
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Company $company): array
    {
        $shipments = (int) $company->getAttribute('shipments_count');
        $orders = (int) $company->getAttribute('orders_count');
        $lastShipment = $company->getAttribute('shipments_max_date');
        $lastOrder = $company->getAttribute('orders_max_erp_created_at');

        return [
            'id' => (int) $company->getKey(),
            'name' => (string) ($company->name ?: $company->legal_name ?: 'Контрагент №'.$company->getKey()),
            'legal_name' => $company->legal_name,
            'tax_id' => $company->tax_id,
            'partner' => $company->user instanceof User ? [
                'id' => (int) $company->user->getKey(),
                'name' => (string) $company->user->display_name,
            ] : null,
            'manager' => $company->user?->personalManager === null ? null : [
                'id' => (int) $company->user->personalManager->getKey(),
                'name' => $company->user->personalManager->name,
            ],
            'shipments_count' => $shipments,
            'shipments_total' => (float) ($company->getAttribute('shipments_sum_total_amount') ?? 0),
            'last_shipment_at' => $lastShipment ? \Illuminate\Support\Carbon::parse($lastShipment)->format('d.m.Y') : null,
            'orders_count' => $orders,
            'last_order_at' => $lastOrder ? \Illuminate\Support\Carbon::parse($lastOrder)->format('d.m.Y') : null,
            // Признак строки: реализация без договора — красный, только заказ — жёлтый.
            'severity' => $shipments > 0 ? 'shipped' : 'ordered',
            'severity_label' => $shipments > 0 ? 'Была реализация' : 'Был заказ',
            'severity_color' => $shipments > 0 ? 'red' : 'orange',
            'terminated_contracts_count' => Contract::query()
                ->where('company_id', $company->getKey())
                ->where('status', ContractStatus::TERMINATED->value)
                ->count(),
        ];
    }
}

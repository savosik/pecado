<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\CrmLead;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Поиск сущности для привязки: партнёр, контрагент, документ, лид.
 *
 * Вынесен из контроллера задач, когда та же выдача понадобилась контактам.
 * Держать её приватным методом одного контроллера значило бы гейтить привязку
 * контакта правом на задачи — менеджер без задач не смог бы привязать бухгалтера.
 *
 * Границу видимости задаёт каждая модель сама; поиск её не расширяет.
 */
class CrmEntitySearch
{
    /**
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    public function search(User $actor, string $type, string $query): array
    {
        $search = trim($query);

        return match ($type) {
            CrmEntityMap::CLIENT => $this->clients($actor, $search),
            CrmEntityMap::CONTRACTOR => $actor->can('crm-contractors.view')
                ? $this->contractors($actor, $search)
                : [],
            CrmEntityMap::ORDER => $this->documents($actor, Order::class, $search, 'Заказ'),
            CrmEntityMap::SHIPMENT => $this->documents($actor, Shipment::class, $search, 'Реализация'),
            CrmEntityMap::LEAD => $this->leads($actor, $search),
            default => [],
        };
    }

    /**
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    public function clients(User $actor, string $search): array
    {
        return User::query()
            ->visibleInCrm($actor)
            ->select('id', 'name', 'erp_name', 'email')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('erp_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderByRaw("COALESCE(NULLIF(erp_name, ''), name)")
            ->take(20)
            ->get()
            ->map(fn (User $client): array => [
                'id' => (int) $client->getKey(),
                'label' => (string) $client->display_name,
                'sublabel' => $client->email,
            ])
            ->all();
    }

    /**
     * Контрагенты — по наименованию, юрнаименованию и ИНН.
     *
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    public function contractors(User $actor, string $search): array
    {
        return Company::query()
            ->visibleInCrm($actor)
            ->select('id', 'user_id', 'name', 'legal_name', 'tax_id')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('legal_name', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")))
            ->with('user:id,name,erp_name')
            ->orderBy('name')
            ->take(20)
            ->get()
            ->map(fn (Company $company): array => [
                'id' => (int) $company->getKey(),
                'label' => (string) ($company->name ?: $company->legal_name ?: 'Контрагент №'.$company->getKey()),
                // Партнёр в подписи важнее ИНН: одноимённые юрлица у разных
                // партнёров в выдаче иначе неразличимы.
                'sublabel' => $company->user instanceof User
                    ? (string) $company->user->display_name
                    : ($company->tax_id === null ? null : 'ИНН '.$company->tax_id),
            ])
            ->all();
    }

    /**
     * Лиды — по имени, организации и контактам.
     *
     * Скоуп берётся из самой модели: у лида партнёра ещё нет, а «ничей» лид
     * намеренно виден всему отделу.
     *
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    public function leads(User $actor, string $search): array
    {
        return CrmLead::query()
            ->visibleTo($actor)
            ->select('id', 'name', 'company_name', 'phone', 'email')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->take(20)
            ->get()
            ->map(fn (CrmLead $lead): array => [
                'id' => (int) $lead->getKey(),
                'label' => (string) $lead->name,
                'sublabel' => $lead->company_name ?: $lead->phone ?: $lead->email,
            ])
            ->all();
    }

    /**
     * Заказы и реализации ищутся одинаково — по номеру, местному и из 1С.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    public function documents(User $actor, string $modelClass, string $search, string $label): array
    {
        return $modelClass::query()
            // Документ без партнёра (партнёрский из 1С) доступен только тем, кто видит
            // весь отдел, — то же правило, что в CrmEntityResolver::canAccess().
            ->when(
                ! $actor->can('crm-department.view'),
                fn (Builder $query) => $query->whereIn(
                    'user_id',
                    User::query()->visibleInCrm($actor)->select('id'),
                ),
            )
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('number', 'like', "%{$search}%")
                ->orWhere('erp_number', 'like', "%{$search}%")))
            ->with('user:id,name,erp_name')
            ->latest('id')
            ->take(20)
            ->get()
            // Через getAttribute(), а не свойствами: класс здесь обобщённый
            // (заказ или реализация), и статический анализ его полей не знает.
            ->map(function (Model $document) use ($label): array {
                $client = $document->getAttribute('user');

                return [
                    'id' => (int) $document->getKey(),
                    'label' => $label.' №'.($document->getAttribute('number') ?: $document->getKey()),
                    'sublabel' => $client instanceof User ? (string) $client->display_name : null,
                ];
            })
            ->all();
    }
}

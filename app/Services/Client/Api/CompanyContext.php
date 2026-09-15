<?php

namespace App\Services\Client\Api;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Контекст юрлица для операций, у которых есть контрагент.
 *
 * Явный `company_id` — только среди своих компаний (чужая = «не найдено», а не
 * «запрещено»: существование чужой записи не подтверждаем). Без него — компания
 * по умолчанию, а если её нет и компания одна — она. Несколько без основной —
 * {@see CompanyRequired}: выбор за клиента не делаем.
 *
 * ИНН принимается только ради переезда интеграций с legacy `/api/client-api`,
 * где контрагент передавался как `inn`.
 */
class CompanyContext
{
    public function resolve(User $user, ?int $companyId = null, ?string $inn = null): Company
    {
        $companies = $user->companies()->orderByDesc('is_default')->orderBy('id')->get();

        if ($companyId !== null) {
            $company = $companies->firstWhere('id', $companyId);

            if ($company === null) {
                throw (new ModelNotFoundException)->setModel(Company::class, [$companyId]);
            }

            return $company;
        }

        if ($inn !== null && $inn !== '') {
            $company = $companies->first(fn (Company $c): bool => (string) $c->tax_id === $inn);

            if ($company === null) {
                throw (new ModelNotFoundException)->setModel(Company::class);
            }

            return $company;
        }

        $default = $companies->first(fn (Company $c): bool => (bool) $c->is_default);

        if ($default !== null) {
            return $default;
        }

        if ($companies->count() === 1) {
            return $companies->first();
        }

        throw new CompanyRequired($companies);
    }

    /**
     * Фильтр списков: явная компания проверяется на принадлежность, иначе — все свои.
     *
     * @return list<int>
     */
    public function filterIds(User $user, ?int $companyId = null): array
    {
        $ids = $user->companies()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($companyId === null) {
            return $ids;
        }

        if (! in_array($companyId, $ids, true)) {
            throw (new ModelNotFoundException)->setModel(Company::class, [$companyId]);
        }

        return [$companyId];
    }
}

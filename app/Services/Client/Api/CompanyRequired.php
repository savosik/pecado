<?php

namespace App\Services\Client\Api;

use App\Models\Company;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Юрлицо не выбрано, а выбрать за клиента нельзя.
 *
 * У партнёра несколько контрагентов и ни один не отмечен основным: заказ,
 * документы и долг живут на контрагенте, и «угадать» здесь означало бы
 * оформить заказ не на то юрлицо. Ответ несёт перечень — агент спросит человека.
 */
class CompanyRequired extends RuntimeException
{
    /**
     * @param  Collection<int, Company>  $companies
     */
    public function __construct(public readonly Collection $companies)
    {
        parent::__construct('Укажите юрлицо (company_id): у вас несколько контрагентов и ни один не отмечен основным.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function choices(): array
    {
        return $this->companies
            ->map(fn (Company $company): array => [
                'id' => (int) $company->getKey(),
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'inn' => $company->tax_id,
                'is_default' => (bool) $company->is_default,
            ])
            ->values()
            ->all();
    }
}

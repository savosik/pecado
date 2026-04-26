<?php

namespace App\Observers;

use App\Listeners\PublishContractorToErp;
use App\Models\CompanyBankAccount;

/**
 * Триггерит contractor.updated (Сайт → 1С) при создании, обновлении или
 * удалении банковских реквизитов синхронизированной с 1С Company.
 *
 * Защита от петли:
 *  - Если родительская Company в режиме обработки входящего сообщения
 *    (Company->fromErp = true) — публикация пропускается.
 *  - HandleContractorUpdated/Created также оборачивают пересборку счетов
 *    в CompanyBankAccount::withoutEvents() — Observer не вызывается вообще.
 */
class CompanyBankAccountObserver
{
    public function created(CompanyBankAccount $bankAccount): void
    {
        $this->publish($bankAccount);
    }

    public function updated(CompanyBankAccount $bankAccount): void
    {
        $this->publish($bankAccount);
    }

    public function deleted(CompanyBankAccount $bankAccount): void
    {
        $this->publish($bankAccount);
    }

    private function publish(CompanyBankAccount $bankAccount): void
    {
        /** @var \App\Models\Company|null $company */
        $company = $bankAccount->company;

        if (! $company) {
            return;
        }

        if ($company->fromErp ?? false) {
            return;
        }

        if (empty($company->erp_id)) {
            // Контрагент ещё не выгружен в 1С — публикация будет позже,
            // через contractor.created при первом заполнении tax_id.
            return;
        }

        $company->loadMissing(['user', 'bankAccounts']);

        PublishContractorToErp::publishCompanyUpdated($company);
    }
}

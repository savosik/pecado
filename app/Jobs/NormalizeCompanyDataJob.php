<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\DataNormalizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Фоновая нормализация данных компании (телефон, email, р/с) через AI.
 *
 * Диспатчится из HandleContractorCreated после сохранения сырых данных.
 */
class NormalizeCompanyDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $companyId,
        public bool $dryRun = false,
    ) {
        $this->queue = 'normalization';
    }

    public function handle(DataNormalizerService $service): void
    {
        $company = Company::withoutGlobalScopes()->find($this->companyId);

        if (! $company) {
            Log::warning('NormalizeCompanyDataJob: компания не найдена', ['company_id' => $this->companyId]);

            return;
        }

        $updates = [];
        $needsAi = ! empty($company->phone) || ! empty($company->email);

        // AI-нормализация телефона и email
        if ($needsAi) {
            $result = $service->normalizeCompany($company->phone, $company->email);

            if ($result) {
                if (isset($result['primary_phone']) && $result['primary_phone'] !== $company->phone) {
                    $updates['phone'] = $result['primary_phone'];
                }
                // Email не обнуляем у компании — можем сломать связи
            }
        }

        // Нормализация р/с — без AI
        $bankAccounts = $company->bankAccounts;
        $bankUpdates = [];

        foreach ($bankAccounts as $account) {
            if ($account->account_number && str_contains($account->account_number, ' ')) {
                $normalized = $service->normalizeAccountNumber($account->account_number);
                $bankUpdates[$account->id] = $normalized;
            }
        }

        if ($this->dryRun) {
            if (! empty($updates) || ! empty($bankUpdates)) {
                Log::info('NormalizeCompanyDataJob [DRY-RUN]', [
                    'company_id'   => $this->companyId,
                    'updates'      => $updates,
                    'bank_updates' => $bankUpdates,
                ]);
            }

            return;
        }

        // Обновляем компанию
        if (! empty($updates)) {
            Company::withoutEvents(function () use ($company, $updates) {
                $company->update($updates);
            });
        }

        // Обновляем р/с
        foreach ($bankUpdates as $accountId => $normalizedNumber) {
            $company->bankAccounts()->where('id', $accountId)->update([
                'account_number' => $normalizedNumber,
            ]);
        }

        if (! empty($updates) || ! empty($bankUpdates)) {
            Log::info('NormalizeCompanyDataJob: данные нормализованы', [
                'company_id'         => $this->companyId,
                'company_updates'    => array_keys($updates),
                'bank_accounts_fixed' => count($bankUpdates),
            ]);
        }
    }
}

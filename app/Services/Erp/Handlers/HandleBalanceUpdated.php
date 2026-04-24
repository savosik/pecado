<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleBalanceUpdated
{
    /**
     * Обработка события balance.updated из 1С.
     * Обновляет балансы по контрагентам (массив contractors[]).
     */
    public function handle(array $payload): void
    {
        $partnerUuid = $payload['partner_uuid'] ?? null;

        if (! $partnerUuid) {
            Log::warning('HandleBalanceUpdated: отсутствует partner_uuid', ['payload' => $payload]);

            return;
        }

        $user = User::where('erp_id', $partnerUuid)->first();

        if (! $user) {
            Log::info('HandleBalanceUpdated: пользователь не найден', ['partner_uuid' => $partnerUuid]);

            return;
        }

        $contractors = $payload['contractors'] ?? [];

        if (empty($contractors)) {
            Log::warning('HandleBalanceUpdated: пустой массив contractors', ['partner_uuid' => $partnerUuid]);

            return;
        }

        $updatedAt = $payload['updated_at'] ?? null;

        DB::transaction(function () use ($user, $contractors, $updatedAt) {
            foreach ($contractors as $contractorData) {
                $contractorInn = $contractorData['tax_id'] ?? null;
                $contractorUuid = $contractorData['uuid'] ?? null;

                if (! $contractorInn) {
                    Log::warning('HandleBalanceUpdated: отсутствует tax_id', ['data' => $contractorData]);

                    continue;
                }

                // Матчинг Company (v13.2): приоритет по erp_id = uuid, fallback по tax_id + user_id.
                $company = null;

                if ($contractorUuid) {
                    $company = Company::withoutGlobalScopes()
                        ->where('user_id', $user->id)
                        ->where('erp_id', $contractorUuid)
                        ->first();
                }

                if (! $company) {
                    $company = Company::withoutGlobalScopes()
                        ->where('user_id', $user->id)
                        ->where('tax_id', $contractorInn)
                        ->first();

                    // Backfill Company.erp_id при наличии UUID в payload
                    if ($company && $contractorUuid && ! $company->erp_id) {
                        Company::withoutEvents(function () use ($company, $contractorUuid) {
                            $company->update(['erp_id' => $contractorUuid]);
                        });
                    }
                }

                $updateData = [
                    'company_id' => $company?->id,
                    'contractor_uuid' => $contractorUuid,
                    'current_balance' => $contractorData['current_balance'] ?? 0,
                    'overdue_debt' => $contractorData['overdue_debt'] ?? 0,
                    'balance_erp_updated_at' => $updatedAt,
                ];

                // Если UUID не пришёл — не перезаписываем существующий contractor_uuid
                if ($contractorUuid === null) {
                    unset($updateData['contractor_uuid']);
                }

                /** @var ContractorBalance $balance */
                $balance = ContractorBalance::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'tax_id' => $contractorInn,
                    ],
                    $updateData
                );

                // Обновить детализацию просрочки: полностью заменяем
                $balance->overdueDetails()->delete();

                $overdueDetails = $contractorData['overdue_details'] ?? [];
                foreach ($overdueDetails as $detail) {
                    if (empty($detail['shipment_uuid'])) {
                        continue;
                    }
                    ContractorBalanceOverdueDetail::create([
                        'contractor_balance_id' => $balance->id,
                        'shipment_uuid' => $detail['shipment_uuid'],
                        'amount' => $detail['amount'] ?? 0,
                        'due_date' => $detail['due_date'],
                    ]);
                }

                Log::info('HandleBalanceUpdated: баланс контрагента обновлён', [
                    'partner_uuid' => $user->erp_id,
                    'user_id' => $user->id,
                    'tax_id' => $contractorInn,
                    'current_balance' => $updateData['current_balance'],
                    'overdue_debt' => $updateData['overdue_debt'],
                    'overdue_count' => count($overdueDetails),
                ]);
            }
        });
    }
}

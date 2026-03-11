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

        if (!$partnerUuid) {
            Log::warning('HandleBalanceUpdated: отсутствует partner_uuid', ['payload' => $payload]);
            return;
        }

        $user = User::where('erp_id', $partnerUuid)->first();

        if (!$user) {
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
                $contractorInn  = $contractorData['contractor_inn'] ?? null;
                $contractorUuid = $contractorData['contractor_uuid'] ?? null;

                if (!$contractorInn) {
                    Log::warning('HandleBalanceUpdated: отсутствует contractor_inn', ['data' => $contractorData]);
                    continue;
                }

                // Найти компанию по ИНН и пользователю
                $company = Company::withoutGlobalScopes()
                    ->where('user_id', $user->id)
                    ->where('tax_id', $contractorInn)
                    ->first();

                $updateData = [
                    'company_id'             => $company?->id,
                    'contractor_uuid'        => $contractorUuid,
                    'current_balance'        => $contractorData['current_balance'] ?? 0,
                    'overdue_debt'           => $contractorData['overdue_debt'] ?? 0,
                    'balance_erp_updated_at' => $updatedAt,
                ];

                /** @var ContractorBalance $balance */
                $balance = ContractorBalance::updateOrCreate(
                    [
                        'user_id'        => $user->id,
                        'contractor_inn' => $contractorInn,
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
                        'shipment_uuid'         => $detail['shipment_uuid'],
                        'amount'                => $detail['amount'] ?? 0,
                        'due_date'              => $detail['due_date'],
                    ]);
                }

                Log::info('HandleBalanceUpdated: баланс контрагента обновлён', [
                    'partner_uuid'     => $user->erp_id,
                    'user_id'          => $user->id,
                    'contractor_inn'   => $contractorInn,
                    'current_balance'  => $updateData['current_balance'],
                    'overdue_debt'     => $updateData['overdue_debt'],
                    'overdue_count'    => count($overdueDetails),
                ]);
            }
        });
    }
}

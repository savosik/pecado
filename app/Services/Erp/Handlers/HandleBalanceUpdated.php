<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\ContractorOrganizationBalance;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Erp\Support\OrganizationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleBalanceUpdated
{
    /**
     * Обработка события balance.updated из 1С.
     * Обновляет балансы по контрагентам (массив contractors[]).
     *
     * v15.8.0: если контрагент несёт `organizations[]`, дополнительно пишется разрез
     * по нашим юрлицам (`contractor_organization_balances`). `ContractorBalance`
     * при этом остаётся агрегатом-проекцией и работает как раньше.
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
        $excludedOrganizationIds = Organization::settlementsExcludedIds();

        DB::transaction(function () use ($user, $contractors, $updatedAt, $excludedOrganizationIds) {
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

                [$currentBalance, $overdueDebt] = $this->withoutExcludedOrganizations(
                    $contractorData,
                    $excludedOrganizationIds,
                );

                $updateData = [
                    'company_id' => $company?->id,
                    'contractor_uuid' => $contractorUuid,
                    'current_balance' => $currentBalance,
                    'overdue_debt' => $overdueDebt,
                    'balance_erp_updated_at' => $updatedAt,
                ];

                // Если UUID не пришёл — не перезаписываем существующий contractor_uuid
                if ($contractorUuid === null) {
                    unset($updateData['contractor_uuid']);
                }

                // Без глобального scope: если 1С всё же пришлёт баланс исключённого
                // контрагента, надо обновить существующую строку, а не создать дубль.
                /** @var ContractorBalance $balance */
                $balance = ContractorBalance::query()
                    ->withoutGlobalScope(ContractorBalance::SCOPE_WITHOUT_EXCLUDED)
                    ->updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'tax_id' => $contractorInn,
                        ],
                        $updateData
                    );

                // v15.8.0: разрез по нашим организациям. Пишем до деталей просрочки,
                // чтобы уметь проставить в них организацию.
                $organizationsByUuid = $this->syncOrganizationBalances(
                    $user,
                    $company,
                    $contractorData,
                    $updatedAt,
                    $excludedOrganizationIds,
                );

                // Обновить детализацию просрочки: полностью заменяем
                $balance->overdueDetails()->delete();

                $overdueDetails = $contractorData['overdue_details'] ?? [];

                // Реализации сайта под UUID-ами просрочки — одним запросом.
                // Из них берётся и FK, и организация строки (org-04).
                $shipmentsByUuid = $this->resolveOverdueShipments($overdueDetails);

                foreach ($overdueDetails as $detail) {
                    if (empty($detail['shipment_uuid'])) {
                        continue;
                    }
                    $shipment = $shipmentsByUuid[$detail['shipment_uuid']] ?? null;
                    $organizationId = $this->resolveOverdueOrganizationId($detail, $organizationsByUuid, $shipment);

                    // Просрочка внутренней организации («Реклама») — не долг клиента.
                    if ($organizationId !== null && in_array($organizationId, $excludedOrganizationIds, true)) {
                        continue;
                    }

                    ContractorBalanceOverdueDetail::create([
                        'contractor_balance_id' => $balance->id,
                        'organization_id' => $organizationId,
                        'shipment_id' => $shipment?->id,
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
                    'organizations_count' => count($contractorData['organizations'] ?? []),
                ]);
            }
        });
    }

    /**
     * Итог контрагента без внутренних организаций (круг 12, v16.7.0).
     *
     * `current_balance` и `overdue_debt` приходят агрегатом по контрагенту и
     * включают расчёты с внутренними организациями («Реклама»). Строки разреза
     * по ним мы не пишем и графики по ним отбрасываем — а агрегат писали целиком,
     * из-за чего в сверке всплывал долг, которого на витрине нет и быть не должно:
     * у клиента 2 785,80 ₽ «просрочки» перед «Рекламой» при нулевом графике.
     *
     * Вычитаем только по данным разреза: без `organizations[]` вычитать не из чего,
     * и агрегат остаётся как есть — соврать в другую сторону хуже.
     *
     * @param  array<string, mixed>  $contractorData
     * @param  list<int>  $excludedOrganizationIds
     * @return array{0: float, 1: float}
     */
    private function withoutExcludedOrganizations(array $contractorData, array $excludedOrganizationIds): array
    {
        $balance = (float) ($contractorData['current_balance'] ?? 0);
        $overdue = (float) ($contractorData['overdue_debt'] ?? 0);

        $organizations = $contractorData['organizations'] ?? null;

        if ($excludedOrganizationIds === [] || ! is_array($organizations) || $organizations === []) {
            return [round($balance, 2), round($overdue, 2)];
        }

        foreach ($organizations as $row) {
            if (! is_array($row)) {
                continue;
            }

            $uuid = $row['uuid'] ?? null;

            if (! is_string($uuid) || trim($uuid) === '') {
                continue;
            }

            // Заглушку здесь не создаём: незнакомая организация исключённой не бывает,
            // а плодить карточки ради вычитания нуля незачем.
            $organizationId = Organization::withTrashed()->where('external_id', trim($uuid))->value('id');

            if ($organizationId === null || ! in_array((int) $organizationId, $excludedOrganizationIds, true)) {
                continue;
            }

            $balance -= (float) ($row['current_balance'] ?? 0);
            $overdue -= (float) ($row['overdue_debt'] ?? 0);
        }

        // Просрочка отрицательной не бывает: если разрез разошёлся с агрегатом,
        // ноль честнее минуса.
        return [round($balance, 2), round(max(0.0, $overdue), 2)];
    }

    /**
     * Разрез задолженности по нашим организациям (v15.8.0).
     *
     * Возвращает карту `uuid организации → id` для проставления организации
     * в детали просрочки. Пустая, если 1С разрез не прислала.
     *
     * @param  array<string, mixed>  $contractorData
     * @param  list<int>  $excludedOrganizationIds
     * @return array<string, int>
     */
    private function syncOrganizationBalances(
        User $user,
        ?Company $company,
        array $contractorData,
        ?string $updatedAt,
        array $excludedOrganizationIds = [],
    ): array {
        $organizations = $contractorData['organizations'] ?? null;

        // Legacy-ветка: разреза нет — ведём себя ровно как до v15.8.0.
        // Пустой массив тоже пропускаем: он не должен молча обнулить историю,
        // расчётов «ни с одной организацией» не бывает при непустом балансе.
        if (! is_array($organizations) || $organizations === []) {
            return [];
        }

        // Разрез привязан к контрагенту клиента. Без Company писать некуда:
        // ключ таблицы — пара (company_id, organization_id).
        if (! $company) {
            Log::warning('HandleBalanceUpdated: разрез по организациям пришёл, но контрагент на сайте не найден', [
                'user_id' => $user->id,
                'tax_id' => $contractorData['tax_id'] ?? null,
            ]);

            return [];
        }

        $this->warnOnSumMismatch($contractorData, $organizations, $company);

        $map = [];
        $touchedOrganizationIds = [];

        foreach ($organizations as $row) {
            $uuid = $row['uuid'] ?? null;

            if (! is_string($uuid) || trim($uuid) === '') {
                Log::warning('HandleBalanceUpdated: строка разреза без uuid организации, пропущена', [
                    'company_id' => $company->id,
                ]);

                continue;
            }

            $organization = app(OrganizationResolver::class)->resolveByUuid($uuid, $row);

            if (! $organization) {
                continue;
            }

            // Внутренняя организация («Реклама») в расчётах с клиентами не участвует:
            // строку разреза не пишем и в карту для деталей просрочки не отдаём.
            if (in_array($organization->id, $excludedOrganizationIds, true)) {
                Log::info('HandleBalanceUpdated: строка разреза исключённой организации пропущена', [
                    'company_id' => $company->id,
                    'organization_id' => $organization->id,
                ]);

                continue;
            }

            $existing = ContractorOrganizationBalance::where('company_id', $company->id)
                ->where('organization_id', $organization->id)
                ->first();

            // Защита от гонки: balance.updated по одному партнёру приходит часто,
            // и сообщение может прийти не по порядку. Более старое игнорируем.
            if ($existing && $this->isStale($existing->balance_erp_updated_at, $updatedAt)) {
                Log::info('HandleBalanceUpdated: устаревшее сообщение разреза, пропущено', [
                    'company_id' => $company->id,
                    'organization_id' => $organization->id,
                ]);

                $map[$uuid] = $organization->id;
                $touchedOrganizationIds[] = $organization->id;

                continue;
            }

            // Без глобального scope — по той же причине, что и агрегат выше.
            ContractorOrganizationBalance::query()
                ->withoutGlobalScope(ContractorBalance::SCOPE_WITHOUT_EXCLUDED)
                ->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'organization_id' => $organization->id,
                    ],
                    [
                        'user_id' => $user->id,
                        'current_balance' => $row['current_balance'] ?? 0,
                        'overdue_debt' => $row['overdue_debt'] ?? 0,
                        'balance_erp_updated_at' => $updatedAt,
                    ],
                );

            $map[$uuid] = $organization->id;
            $touchedOrganizationIds[] = $organization->id;
        }

        // Организация исчезла из массива — расчётов с ней больше нет. Строку
        // обнуляем, но НЕ удаляем: иначе погашенный долг пропадёт из истории
        // и отчёты задним числом изменятся.
        ContractorOrganizationBalance::query()
            ->withoutGlobalScope(ContractorBalance::SCOPE_WITHOUT_EXCLUDED)
            ->where('company_id', $company->id)
            ->when($touchedOrganizationIds !== [], fn ($q) => $q->whereNotIn('organization_id', $touchedOrganizationIds))
            ->update([
                'current_balance' => 0,
                'overdue_debt' => 0,
                'balance_erp_updated_at' => $updatedAt,
            ]);

        return $map;
    }

    /**
     * Расхождение суммы разреза с contractor-уровнем.
     *
     * Источник истины — **contractor-уровень**: именно его клиент видит как итог,
     * и обнулять итог из-за неразнесённых расчётов нельзя. Расхождение почти наверняка
     * значит, что часть расчётов в 1С не разложена по организациям, поэтому пишем
     * предупреждение: количество таких сообщений — индикатор готовности 1С.
     *
     * @param  array<string, mixed>  $contractorData
     * @param  array<int, array<string, mixed>>  $organizations
     */
    private function warnOnSumMismatch(array $contractorData, array $organizations, Company $company): void
    {
        $contractorTotal = round((float) ($contractorData['current_balance'] ?? 0), 2);
        $organizationsTotal = round(array_sum(array_map(
            static fn ($row) => (float) ($row['current_balance'] ?? 0),
            $organizations,
        )), 2);

        if (abs($contractorTotal - $organizationsTotal) < 0.01) {
            return;
        }

        Log::warning('HandleBalanceUpdated: сумма разреза по организациям не сходится с балансом контрагента', [
            'company_id' => $company->id,
            'tax_id' => $contractorData['tax_id'] ?? null,
            'contractor_balance' => $contractorTotal,
            'organizations_sum' => $organizationsTotal,
            'difference' => round($contractorTotal - $organizationsTotal, 2),
        ]);
    }

    /**
     * Реализации сайта под UUID-ами строк просрочки.
     *
     * Найдётся не всё: баланс и реализации приезжают разными очередями без
     * гарантии порядка, поэтому отсутствие реализации — штатная ситуация.
     * Связь для таких строк доклеит HandleShipmentCreated, когда документ придёт.
     *
     * @param  array<int, array<string, mixed>>  $overdueDetails
     * @return array<string, Shipment>
     */
    private function resolveOverdueShipments(array $overdueDetails): array
    {
        $uuids = array_values(array_filter(
            array_map(fn ($detail) => $detail['shipment_uuid'] ?? null, $overdueDetails),
            fn ($uuid) => is_string($uuid) && $uuid !== '',
        ));

        if ($uuids === []) {
            return [];
        }

        return Shipment::whereIn('uuid', array_unique($uuids))
            ->get(['id', 'uuid', 'organization_id'])
            ->keyBy('uuid')
            ->all();
    }

    /**
     * Организация строки просрочки: из payload либо по реализации `shipment_uuid`.
     *
     * @param  array<string, mixed>  $detail
     * @param  array<string, int>  $organizationsByUuid
     */
    private function resolveOverdueOrganizationId(array $detail, array $organizationsByUuid, ?Shipment $shipment): ?int
    {
        $uuid = $detail['organization_uuid'] ?? null;

        if (is_string($uuid) && isset($organizationsByUuid[$uuid])) {
            return $organizationsByUuid[$uuid];
        }

        if (is_string($uuid) && trim($uuid) !== '') {
            return app(OrganizationResolver::class)->resolveByUuid($uuid)?->id;
        }

        // Организацию не прислали — выводим по реализации (org-04).
        // Не нашлась или у неё нет организации — NULL, сумма всё равно учитывается.
        return $shipment?->organization_id;
    }

    /**
     * Сообщение старше уже сохранённой метки актуальности.
     */
    private function isStale(?\Illuminate\Support\Carbon $saved, ?string $incoming): bool
    {
        if (! $saved || ! $incoming) {
            return false;
        }

        try {
            return \Illuminate\Support\Carbon::parse($incoming)->lt($saved);
        } catch (\Throwable) {
            return false;
        }
    }
}

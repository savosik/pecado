<?php

namespace App\Services\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Crm\SalesSheet;
use App\Support\Crm\SalesSheetImportReport;
use App\Support\Crm\SalesSheetRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Перенос управленческой таблицы продаж в CRM: планы и паспорта клиентов.
 *
 * Клиенты ищутся по имени только на точное совпадение. Похожесть здесь опаснее
 * пропуска: неверно угаданный однофамилец получит чужой план на миллионы, и
 * заметят это не раньше конца месяца, тогда как ненайденный клиент честно
 * попадает в отчёт и доводится руками.
 */
class SalesSheetImporter
{
    use Concerns\MergesSheetClients;

    public function __construct(
        private readonly SalesPlanService $plans,
        private readonly ClientProfileService $profiles,
        private readonly ClientLifecycleService $lifecycle,
    ) {}

    /**
     * @param  bool  $overwrite  перезаписывать уже заполненные поля паспорта и
     *                           статус, выставленный менеджером вручную
     */
    public function import(
        SalesSheet $sheet,
        User $author,
        bool $dryRun = false,
        bool $overwrite = false,
    ): SalesSheetImportReport {
        $report = new SalesSheetImportReport;
        $report->warnings = $sheet->warnings;

        $groups = $this->groupByName($sheet->clients(), $report);
        $index = $this->clientIndex();

        // План менеджера собирается из планов его клиентов, поэтому сначала
        // раскладываем клиентов, а уже потом сводим итоги по менеджерам.
        /** @var array<int, array<string, float>> $byManager */
        $byManager = [];

        $apply = function () use ($sheet, $groups, $index, $author, $overwrite, $report, &$byManager): void {
            foreach ($groups as $group) {
                $row = $group['row'];
                $amount = array_sum($row->plans);
                $matches = $index[$group['key']] ?? [];

                if ($matches === []) {
                    $report->addUnmatched($row->name, $row->line, $amount);

                    continue;
                }

                if (count($matches) > 1) {
                    $report->addAmbiguous($row->name, $row->line, count($matches), $amount);

                    continue;
                }

                $client = $matches[0];
                $report->clientsMatched++;

                $this->applyPlans($row, $client, $author, $report);
                $this->applyProfile($row, $client, $author, $overwrite, $report);

                $managerId = $client->personal_manager_id;

                if ($managerId !== null) {
                    foreach ($row->plans as $month => $sum) {
                        $byManager[$managerId][$month] = ($byManager[$managerId][$month] ?? 0) + $sum;
                    }
                }
            }

            $this->applyNewClientBuckets($sheet->newClientBuckets(), $byManager, $report);
            $this->applyManagerPlans($byManager, $author, $report);
            $this->applyDepartmentPlans($sheet->departmentPlans, $author, $report);
        };

        if ($dryRun) {
            // Прогон целиком внутри откатываемой транзакции: считаются ровно те же
            // ветки, что и при записи, поэтому отчёт совпадает с боевым запуском.
            DB::beginTransaction();

            try {
                $apply();
            } finally {
                DB::rollBack();
            }

            return $report;
        }

        DB::transaction($apply);

        return $report;
    }

    /**
     * Клиенты по нормализованному имени: и по названию из 1С, и по имени на сайте.
     *
     * @return array<string, list<User>>
     */
    private function clientIndex(): array
    {
        $index = [];

        User::query()
            ->clients()
            ->select('id', 'name', 'erp_name', 'personal_manager_id')
            ->each(function (User $client) use (&$index): void {
                foreach ([$client->erp_name, $client->name] as $candidate) {
                    $key = $this->normalizeName((string) $candidate);

                    if ($key === '') {
                        continue;
                    }

                    // Один и тот же клиент не должен попасть в список дважды
                    // (erp_name и name часто совпадают) — иначе он выглядел бы
                    // неоднозначным и был бы пропущен.
                    if (! isset($index[$key])) {
                        $index[$key] = [];
                    }

                    $ids = array_map(fn (User $found): int => (int) $found->getKey(), $index[$key]);

                    if (! in_array((int) $client->getKey(), $ids, true)) {
                        $index[$key][] = $client;
                    }
                }
            });

        return $index;
    }

    private function applyPlans(SalesSheetRow $row, User $client, User $author, SalesSheetImportReport $report): void
    {
        foreach ($row->plans as $month => $amount) {
            $this->plans->set(
                PlanTarget::CLIENT,
                (int) $client->getKey(),
                $this->month($month),
                $amount,
                $author,
            );

            $report->plansSaved++;
        }
    }

    private function applyProfile(
        SalesSheetRow $row,
        User $client,
        User $author,
        bool $overwrite,
        SalesSheetImportReport $report,
    ): void {
        $attributes = $row->profileAttributes();
        $profile = $this->profiles->forClient($client);

        if ($attributes !== []) {
            $changes = $overwrite
                ? $attributes
                // Заполненное менеджером не трогаем: таблица описывает клиента
                // крупными мазками, а в карточке могли уточнить руками.
                : array_filter(
                    $attributes,
                    fn (string $field): bool => $profile->getAttribute($field) === null,
                    ARRAY_FILTER_USE_KEY,
                );

            if ($changes !== []) {
                $this->profiles->update($client, $changes, $author);
                $report->profilesUpdated++;
            }
        }

        if ($row->status === null) {
            return;
        }

        // Статус, который менеджер уже менял руками, — управленческое решение;
        // без явного разрешения импорт его не переписывает.
        $changedByHand = $profile->exists && $profile->lifecycle_changed_at !== null;

        if ($changedByHand && ! $overwrite) {
            return;
        }

        if ($profile->exists && $profile->lifecycle_status === $row->status) {
            return;
        }

        $this->lifecycle->change($client, $row->status, $author, 'Импорт таблицы продаж');
        $report->statusesChanged++;
    }

    /**
     * План на ещё не привлечённых клиентов — в план менеджера, которому он поставлен.
     *
     * @param  list<SalesSheetRow>  $buckets
     * @param  array<int, array<string, float>>  $byManager
     */
    private function applyNewClientBuckets(array $buckets, array &$byManager, SalesSheetImportReport $report): void
    {
        /** @var array<int, string> $managers */
        $managers = PersonalManager::query()->pluck('name', 'id')->all();

        foreach ($buckets as $bucket) {
            $managerId = $this->matchManagerId($bucket->manager, $managers);

            if ($managerId === null) {
                $report->addUnmatched(
                    sprintf('%s (менеджер «%s»)', $bucket->name, $bucket->manager ?? '—'),
                    $bucket->line,
                    array_sum($bucket->plans),
                );

                continue;
            }

            foreach ($bucket->plans as $month => $amount) {
                $byManager[$managerId][$month] = ($byManager[$managerId][$month] ?? 0) + $amount;
            }
        }
    }

    /**
     * @param  array<int, array<string, float>>  $byManager
     */
    private function applyManagerPlans(array $byManager, User $author, SalesSheetImportReport $report): void
    {
        foreach ($byManager as $managerId => $months) {
            foreach ($months as $month => $amount) {
                $this->plans->set(PlanTarget::MANAGER, $managerId, $this->month($month), $amount, $author);
                $report->managerPlansSaved++;
            }
        }
    }

    /**
     * @param  array<string, float>  $months
     */
    private function applyDepartmentPlans(array $months, User $author, SalesSheetImportReport $report): void
    {
        foreach ($months as $month => $amount) {
            $this->plans->set(PlanTarget::DEPARTMENT, null, $this->month($month), $amount, $author);
            $report->departmentPlansSaved++;
        }
    }

    private function month(string $month): Carbon
    {
        return Carbon::parse($month.'-01')->startOfMonth()->startOfDay();
    }
}

<?php

namespace App\Services\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Crm\SalesSheet;
use App\Support\Crm\SalesSheetImportReport;
use App\Support\Crm\SalesSheetRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
     * Строки одного клиента, слитые в одну.
     *
     * В таблице клиент иногда заведён дважды (два договора, переезд строки вниз
     * списка). Планы таких строк складываются: в CRM клиент один, и его план —
     * это всё, что на него запланировано.
     *
     * @param  list<SalesSheetRow>  $rows
     * @return list<array{key: string, row: SalesSheetRow}>
     */
    private function groupByName(array $rows, SalesSheetImportReport $report): array
    {
        /** @var array<string, SalesSheetRow> $merged */
        $merged = [];

        foreach ($rows as $row) {
            $key = $this->normalizeName($row->name);

            if ($key === '') {
                continue;
            }

            if (! isset($merged[$key])) {
                $merged[$key] = $row;

                continue;
            }

            $previous = $merged[$key];
            $plans = $previous->plans;

            foreach ($row->plans as $month => $amount) {
                $plans[$month] = ($plans[$month] ?? 0) + $amount;
            }

            $report->warnings[] = sprintf(
                'Клиент «%s» встречается в строках %d и %d — планы сложены.',
                $row->name,
                $previous->line,
                $row->line,
            );

            $merged[$key] = new SalesSheetRow(
                line: $previous->line,
                name: $previous->name,
                kind: $previous->kind,
                manager: $previous->manager ?? $row->manager,
                // Паспорт берём из той строки, где он заполнен: дубль обычно
                // заводят пустым, и он затёр бы данные основной строки.
                status: $previous->status ?? $row->status,
                businessType: $previous->businessType ?? $row->businessType,
                hasOfflinePoints: $previous->hasOfflinePoints ?? $row->hasOfflinePoints,
                hasOnlineStore: $previous->hasOnlineStore ?? $row->hasOnlineStore,
                worksWithMarketplaces: $previous->worksWithMarketplaces ?? $row->worksWithMarketplaces,
                pointsCount: $previous->pointsCount ?? $row->pointsCount,
                plans: $plans,
            );
        }

        return array_map(
            fn (string $key, SalesSheetRow $row): array => ['key' => $key, 'row' => $row],
            array_keys($merged),
            array_values($merged),
        );
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
        $managers = PersonalManager::query()->select('id', 'name')->get();

        foreach ($buckets as $bucket) {
            $manager = $this->matchManager($bucket->manager, $managers);

            if ($manager === null) {
                $report->addUnmatched(
                    sprintf('%s (менеджер «%s»)', $bucket->name, $bucket->manager ?? '—'),
                    $bucket->line,
                    array_sum($bucket->plans),
                );

                continue;
            }

            foreach ($bucket->plans as $month => $amount) {
                $byManager[(int) $manager->getKey()][$month] = ($byManager[(int) $manager->getKey()][$month] ?? 0) + $amount;
            }
        }
    }

    /**
     * @param  Collection<int, PersonalManager>  $managers
     */
    private function matchManager(?string $name, Collection $managers): ?PersonalManager
    {
        if ($name === null) {
            return null;
        }

        // В таблице менеджер записан с городом: «Москва: Курочкина Алёна Валерьевна».
        $withoutCity = str_contains($name, ':') ? substr($name, strpos($name, ':') + 1) : $name;
        $key = $this->normalizeName($withoutCity);

        if ($key === '') {
            return null;
        }

        $exact = $managers->first(
            fn (PersonalManager $manager): bool => $this->normalizeName((string) $manager->name) === $key,
        );

        if ($exact !== null) {
            return $exact;
        }

        // Запасной путь — фамилия: в таблице отчество пишут не всегда.
        $surname = explode(' ', $key)[0];

        $bySurname = $managers->filter(
            fn (PersonalManager $manager): bool => str_starts_with($this->normalizeName((string) $manager->name), $surname.' '),
        );

        return $bySurname->count() === 1 ? $bySurname->first() : null;
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

    /**
     * Имя для сравнения: регистр, «ё», лишние пробелы и хвостовая точка не считаются.
     *
     * Пробелы вокруг запятых убираются тоже — «ИП, г.Москва» и «ИП,г. Москва»
     * в таблице и в 1С пишут по-разному, а клиент за ними один и тот же.
     */
    private function normalizeName(string $name): string
    {
        $text = mb_strtolower(trim($name));
        $text = str_replace(["\u{00A0}", 'ё', '«', '»', '"'], [' ', 'е', '', '', ''], $text);
        $text = (string) preg_replace('/\s*([,.])\s*/u', '$1', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text, " \t\n\r\0\x0B.,");
    }
}

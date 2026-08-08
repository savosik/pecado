<?php

namespace App\Services\Crm;

use App\Enums\Crm\PlanTarget;
use App\Support\Crm\SalesSheet;
use App\Support\Crm\SalesSheetImportReport;
use App\Support\Crm\SalesSheetRow;

/**
 * Тот же импорт таблицы продаж, но через агентское API — для боевой CRM.
 *
 * Правила сопоставления имён и слияния дублей общие с {@see SalesSheetImporter}
 * (трейт `MergesSheetClients`): один и тот же файл обязан давать один и тот же
 * набор сопоставленных клиентов, где бы импорт ни выполнялся.
 *
 * Отличие только в границах атомарности. Локальный импорт живёт в транзакции и
 * откатывается целиком; здесь каждый вызов API самостоятелен, поэтому прерванный
 * прогон оставляет часть данных записанной. Это терпимо ровно потому, что импорт
 * идемпотентен: повторный запуск доводит начатое, а не удваивает планы.
 */
class SalesSheetApiImporter
{
    use Concerns\MergesSheetClients;

    /** Клиентов на страницу: верхняя граница, которую принимает `client.list`. */
    private const PAGE_SIZE = 100;

    public function __construct(private readonly CrmApiClient $api) {}

    public function import(SalesSheet $sheet, bool $dryRun = false, bool $overwrite = false): SalesSheetImportReport
    {
        $report = new SalesSheetImportReport;
        $report->warnings = $sheet->warnings;

        $groups = $this->groupByName($sheet->clients(), $report);

        [$index, $managerOfClient, $managers] = $this->fetchClients();

        /** @var array<int, array<string, float>> $byManager */
        $byManager = [];

        /** @var array<string, list<array<string, mixed>>> $planRows */
        $planRows = [];

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

            $clientId = $matches[0];
            $report->clientsMatched++;

            foreach ($row->plans as $month => $sum) {
                $planRows[$month][] = [
                    'target_type' => PlanTarget::CLIENT->value,
                    'target_id' => $clientId,
                    'amount' => $sum,
                ];
            }

            $managerId = $managerOfClient[$clientId] ?? null;

            if ($managerId !== null) {
                foreach ($row->plans as $month => $sum) {
                    $byManager[$managerId][$month] = ($byManager[$managerId][$month] ?? 0) + $sum;
                }
            }

            if (! $dryRun) {
                $this->pushProfile($row, $clientId, $overwrite, $report);
            } else {
                $this->countProfile($row, $report);
            }
        }

        $this->collectNewClientBuckets($sheet->newClientBuckets(), $managers, $byManager, $report);

        foreach ($byManager as $managerId => $months) {
            foreach ($months as $month => $amount) {
                $planRows[$month][] = [
                    'target_type' => PlanTarget::MANAGER->value,
                    'target_id' => $managerId,
                    'amount' => $amount,
                ];
                $report->managerPlansSaved++;
            }
        }

        foreach ($sheet->departmentPlans as $month => $amount) {
            $planRows[$month][] = [
                'target_type' => PlanTarget::DEPARTMENT->value,
                'amount' => $amount,
            ];
            $report->departmentPlansSaved++;
        }

        $report->plansSaved = array_sum(array_map(
            fn (array $rows): int => count(array_filter(
                $rows,
                fn (array $row): bool => $row['target_type'] === PlanTarget::CLIENT->value,
            )),
            $planRows,
        ));

        if (! $dryRun) {
            $this->pushPlans($planRows, $report);
        }

        return $report;
    }

    /**
     * Клиенты боевой CRM: индекс по имени, менеджер каждого клиента и список менеджеров.
     *
     * @return array{0: array<string, list<int>>, 1: array<int, int>, 2: array<int, string>}
     */
    private function fetchClients(): array
    {
        $index = [];
        $managerOfClient = [];
        $managers = [];

        $page = 1;

        do {
            $response = $this->api->get('clients', [
                'per_page' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            /** @var list<array<string, mixed>> $rows */
            $rows = $response['data'] ?? [];

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $key = $this->normalizeName((string) ($row['name'] ?? ''));

                if ($id === 0 || $key === '') {
                    continue;
                }

                if (! isset($index[$key])) {
                    $index[$key] = [];
                }

                if (! in_array($id, $index[$key], true)) {
                    $index[$key][] = $id;
                }

                $manager = $row['manager'] ?? null;

                if (is_array($manager) && isset($manager['id'])) {
                    $managerOfClient[$id] = (int) $manager['id'];
                    $managers[(int) $manager['id']] = (string) ($manager['name'] ?? '');
                }
            }

            $lastPage = (int) ($response['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        return [$index, $managerOfClient, $managers];
    }

    /**
     * @param  list<SalesSheetRow>  $buckets
     * @param  array<int, string>  $managers
     * @param  array<int, array<string, float>>  $byManager
     */
    private function collectNewClientBuckets(
        array $buckets,
        array $managers,
        array &$byManager,
        SalesSheetImportReport $report,
    ): void {
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
     * Планы уходят помесячно: сетка правится строками, и одному месяцу
     * соответствует один вызов вместо двух сотен.
     *
     * @param  array<string, list<array<string, mixed>>>  $planRows
     */
    private function pushPlans(array $planRows, SalesSheetImportReport $report): void
    {
        ksort($planRows);

        foreach ($planRows as $month => $rows) {
            $result = $this->api->post('plans', ['month' => $month, 'rows' => $rows]);

            $skipped = (int) ($result['skipped'] ?? 0);

            if ($skipped > 0) {
                $report->warnings[] = sprintf(
                    'Месяц %s: сервер пропустил %d строк — у сотрудника нет права ставить план этим целям.',
                    $month,
                    $skipped,
                );
            }
        }
    }

    /**
     * Паспорт и статус клиента через API.
     *
     * Текущее состояние читается перед записью: без него «дополнять, но не
     * затирать» превратилось бы в «затирать всегда», а уточнения менеджеров
     * ценнее крупных мазков таблицы.
     */
    private function pushProfile(SalesSheetRow $row, int $clientId, bool $overwrite, SalesSheetImportReport $report): void
    {
        $attributes = $row->profileAttributes();

        if ($attributes === [] && $row->status === null) {
            return;
        }

        $current = $this->api->get("clients/{$clientId}/profile");

        if ($attributes !== []) {
            $changes = $overwrite
                ? $attributes
                : array_filter(
                    $attributes,
                    fn (string $field): bool => ($current[$field] ?? null) === null,
                    ARRAY_FILTER_USE_KEY,
                );

            if ($changes !== []) {
                $this->api->patch("clients/{$clientId}/profile", $changes);
                $report->profilesUpdated++;
            }
        }

        if ($row->status === null) {
            return;
        }

        $changedByHand = ($current['lifecycle_changed_at'] ?? null) !== null;

        if ($changedByHand && ! $overwrite) {
            return;
        }

        if (($current['lifecycle_status'] ?? null) === $row->status->value) {
            return;
        }

        $this->api->post("clients/{$clientId}/lifecycle", [
            'lifecycle_status' => $row->status->value,
            'reason' => 'Импорт таблицы продаж',
        ]);

        $report->statusesChanged++;
    }

    /**
     * Пробный прогон не ходит за профилями: две сотни лишних запросов к боевому
     * серверу ради чисел, которые всё равно оценочные.
     */
    private function countProfile(SalesSheetRow $row, SalesSheetImportReport $report): void
    {
        if ($row->profileAttributes() !== []) {
            $report->profilesUpdated++;
        }

        if ($row->status !== null) {
            $report->statusesChanged++;
        }
    }
}

<?php

namespace App\Services\Crm\Concerns;

use App\Support\Crm\ClientNameIndex;
use App\Support\Crm\SalesSheetImportReport;
use App\Support\Crm\SalesSheetRow;

/**
 * Приведение строк таблицы к клиентам: слияние дублей и ключ для сравнения имён.
 *
 * Общий код двух импортёров — того, что пишет в базу напрямую, и того, что ходит
 * через API. Разойдись у них правила сравнения имён, один и тот же файл дал бы
 * на dev и на проде разный набор сопоставленных клиентов, и объяснить это
 * расхождение было бы нечем.
 */
trait MergesSheetClients
{
    /**
     * Строки одного клиента, слитые в одну.
     *
     * В таблице клиент иногда заведён дважды (два договора, переехавшая вниз
     * строка). Планы таких строк складываются: в CRM клиент один, и его план —
     * это всё, что на него запланировано.
     *
     * @param  list<SalesSheetRow>  $rows
     * @return list<array{key: string, row: SalesSheetRow}>
     */
    protected function groupByName(array $rows, SalesSheetImportReport $report): array
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
     * Имя для сравнения строк таблицы между собой.
     *
     * Здесь именно точное сравнение, а не «ядро» из {@see ClientNameIndex}: две
     * строки таблицы описывают одного клиента, когда названы одинаково, и
     * складывать планы разным клиентам из-за общей фамилии недопустимо.
     */
    protected function normalizeName(string $name): string
    {
        return ClientNameIndex::normalize($name);
    }

    /**
     * Менеджер из таблицы записан с городом: «Москва: Курочкина Алёна Валерьевна».
     *
     * @param  array<int, string>  $managers  id => имя
     */
    protected function matchManagerId(?string $name, array $managers): ?int
    {
        if ($name === null) {
            return null;
        }

        $withoutCity = str_contains($name, ':') ? substr($name, strpos($name, ':') + 1) : $name;
        $key = $this->normalizeName($withoutCity);

        if ($key === '') {
            return null;
        }

        foreach ($managers as $id => $managerName) {
            if ($this->normalizeName($managerName) === $key) {
                return $id;
            }
        }

        // Запасной путь — фамилия: в таблице отчество пишут не всегда.
        $surname = explode(' ', $key)[0];
        $found = [];

        foreach ($managers as $id => $managerName) {
            if (str_starts_with($this->normalizeName($managerName), $surname.' ')) {
                $found[] = $id;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}

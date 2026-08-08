<?php

namespace App\Support\Crm;

/**
 * Разобранная таблица продаж: строки клиентов и итог по отделу.
 *
 * Итог отдела приходит отдельным полем, а не строкой: в таблице он живёт в шапке
 * (в той же строке, где стоят подписи «Статус клиента» и «Менеджер») и клиентом
 * не является — искать его в базе было бы ошибкой.
 */
final class SalesSheet
{
    /**
     * @param  list<SalesSheetRow>  $rows
     * @param  array<string, float>  $departmentPlans  план отдела по месяцам: 'YYYY-MM' => сумма
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $departmentPlans = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * @return list<SalesSheetRow>
     */
    public function clients(): array
    {
        return array_values(array_filter(
            $this->rows,
            fn (SalesSheetRow $row): bool => $row->kind === SalesSheetRow::KIND_CLIENT,
        ));
    }

    /**
     * @return list<SalesSheetRow>
     */
    public function newClientBuckets(): array
    {
        return array_values(array_filter(
            $this->rows,
            fn (SalesSheetRow $row): bool => $row->kind === SalesSheetRow::KIND_NEW_CLIENTS,
        ));
    }
}

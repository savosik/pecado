<?php

namespace App\Events\Payroll;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * У менеджеров изменились входы расчёта зарплаты: отгрузки, оплаты, планы, параметры.
 *
 * Бросают проектор накладных, наблюдатели отгрузок и планов, экран настроек.
 * Слушатель ставит пересчёт черновика с дебаунсом; месяц `null` — текущий.
 */
class PayrollInputsChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $managerIds  personal_managers.id
     * @param  list<string>  $months  затронутые месяцы (Y-m-01); пусто — текущий
     */
    public function __construct(
        public readonly array $managerIds,
        public readonly string $source,
        public readonly array $months = [],
    ) {}
}

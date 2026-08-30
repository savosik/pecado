<?php

namespace App\Services\Payroll\Contracts;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;

/**
 * Компонент дохода менеджера или фактор KPI-премии.
 *
 * Компонент — формула; его параметры — данные схемы и отклонений. Компонент
 * не ходит в БД: всё, что нужно для расчёта, лежит в {@see PayrollContext},
 * чтобы прогноз и советы могли считать его же на гипотетических входах.
 */
interface PayrollComponent
{
    /** Ключ из config/payroll.php. */
    public function key(): string;

    /** Название для экрана. */
    public function label(): string;

    /** Что это за показатель — одной-двумя фразами для менеджера. */
    public function description(): string;

    /** Как считается — общий текст без чисел. */
    public function howComputed(): string;

    public function kind(): ComponentKind;

    /**
     * JSON Schema параметров (массив), для валидации и подсказок формы.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;

    /**
     * Умолчания параметров, если схема ничего не задала.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * Доменные проверки, которые JSON Schema не выразит (монотонность лестниц).
     *
     * @param  array<string, mixed>  $params
     * @return list<string> сообщения об ошибках, пусто — всё в порядке
     */
    public function validateParams(array $params): array;

    /**
     * @param  array<string, mixed>  $params  действующие параметры этого компонента
     */
    public function compute(PayrollContext $context, array $params): ComponentResult;
}

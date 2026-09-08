<?php

namespace App\Services\Motivation;

use App\Services\Payroll\Support\Money;

/**
 * Квартальная премия отдела (раздел 7 Положения).
 *
 * Чистая функция: перечень «партнёр → объём за квартал» и параметры приказа
 * дают количество квалифицированных, достигнутую ступень и сумму.
 *
 * Зачёт проверяется по каждому партнёру отдельно (п. 7.3). Деление совокупного
 * объёма на количество здесь невозможно не по недосмотру, а намеренно: на живых
 * данных июня–августа 2026 оно дало бы 9 «квалифицированных» вместо 4
 * и ошибочное начисление первой ступени.
 *
 * Премия начисляется на отдел, на показатели раздела 6 не влияет и Личный план
 * не изменяет (пп. 7.6, 7.7) — поэтому она живёт отдельным расчётом, а не внутри
 * месячного снимка.
 */
class QuarterlyBonusCalculator
{
    /**
     * @param  list<array{user_id?: int, amount: float|int, returns?: float|int}>  $partners
     * @param  list<array{count: int, amount: float|int}>  $steps  ступени приказа, по возрастанию
     * @return array{qualified: list<int>, qualified_count: int, step: int, amount: float, total: float}
     */
    public function evaluate(array $partners, float $threshold, array $steps): array
    {
        $qualified = [];
        $total = 0.0;

        foreach ($partners as $partner) {
            $net = (float) $partner['amount'] - (float) ($partner['returns'] ?? 0);
            $total += $net;

            if ($net >= $threshold) {
                $qualified[] = (int) ($partner['user_id'] ?? 0);
            }
        }

        $count = count($qualified);
        $step = 0;
        $amount = 0.0;

        foreach ($steps as $index => $row) {
            if ($count >= (int) $row['count']) {
                $step = $index + 1;
                $amount = (float) $row['amount'];
            }
        }

        return [
            'qualified' => $qualified,
            'qualified_count' => $count,
            'step' => $step,
            'amount' => Money::round($amount),
            'total' => Money::round($total),
        ];
    }

    /**
     * Сколько партнёров и сколько рублей не хватает до следующей ступени.
     *
     * Экран квартальной премии обязан отвечать на этот вопрос: на живых данных
     * ступень не достигается, и без него список выглядит поражением, а не задачей.
     *
     * @param  list<array{count: int, amount: float|int}>  $steps
     * @return array{count: int, amount: float}|null null — достигнута верхняя ступень
     */
    public function nextStep(int $qualifiedCount, array $steps): ?array
    {
        foreach ($steps as $row) {
            if ($qualifiedCount < (int) $row['count']) {
                return [
                    'count' => (int) $row['count'] - $qualifiedCount,
                    'amount' => (float) $row['amount'],
                ];
            }
        }

        return null;
    }
}

<?php

namespace App\Services\Payroll\Components\Motivation;

use App\Enums\Payroll\ComponentKind;
use App\Services\Payroll\Components\AbstractComponent;
use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;
use App\Services\Payroll\Support\Money;

/**
 * Переменная часть по Положению редакции 2.2 (раздел 6).
 *
 *     Переменная часть = min( Предельный размер, max( 0, П1 + П2 + П3 − К1 ) )
 *
 * Компонент-контейнер по образцу {@see \App\Services\Payroll\Components\KpiBonusComponent}:
 * владеет четырьмя показателями, порядком их применения и обоими ограничениями.
 * Ограничения принадлежат группе, а не любому из слагаемых (п. 6.6), — именно
 * поэтому нужен контейнер, а не четыре независимых компонента в схеме.
 *
 * Расширить KpiBonusComponent было нельзя: там зашита семантика «база × выполнение»,
 * а здесь сумма четырёх независимых показателей.
 *
 * Четыре показателя внедряются напрямую, а не через каталог: их состав задан
 * Положением и настройке не подлежит. Каталог — граница того, что схема может
 * включить; П1…К1 включаются только вместе с переменной частью.
 *
 * Эффект каждого показателя в рублях считается изоляцией: переменная часть без него
 * минус переменная часть с ним. Тем же приёмом работают прогноз, советы
 * и предпросмотр изменения параметров — второй формулы в системе нет.
 */
class VariablePartComponent extends AbstractComponent
{
    public function __construct(
        private readonly BaseSalesPart $p1,
        private readonly NewPartnersPart $p2,
        private readonly FocusRangePart $p3,
        private readonly OverdueDebtPart $k1,
    ) {}

    public function key(): string
    {
        return 'motivation_variable';
    }

    public function label(): string
    {
        return 'Переменная часть';
    }

    public function description(): string
    {
        return 'Вознаграждение за продажи: база сверх порога, новые партнёры, фокус-товары. Уменьшается вычетом за просроченную задолженность.';
    }

    public function howComputed(): string
    {
        return 'П1 + П2 + П3 − К1. Ниже нуля не опускается, выше предельного размера не растёт. Ограничения применяются к сумме, а не к отдельным показателям.';
    }

    public function kind(): ComponentKind
    {
        return ComponentKind::AMOUNT;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payment_threshold' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Порог оплаты, доля личного плана'],
                'rate_p1' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Ставка П1, доля'],
                'rate_p2' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Ставка П2, доля'],
                'rate_p3' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Ставка П3, доля'],
                'rate_k1_per_day' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'title' => 'Ставка К1 за календарный день, доля'],
                'cap' => ['type' => 'number', 'minimum' => 0, 'title' => 'Предельный размер переменной части, ₽'],
            ],
            'required' => ['payment_threshold', 'rate_p1', 'cap'],
            'additionalProperties' => false,
        ];
    }

    public function defaults(): array
    {
        $appendix = (array) config('motivation.default_parameters', []);

        return [
            'payment_threshold' => $appendix['payment_threshold'] ?? 0.6,
            'rate_p1' => $appendix['rate_p1'] ?? 0.019,
            'rate_p2' => $appendix['rate_p2'] ?? 0.03,
            'rate_p3' => $appendix['rate_p3'] ?? 0.01,
            'rate_k1_per_day' => $appendix['rate_k1_per_day'] ?? 0.0005,
            'cap' => $appendix['variable_cap'] ?? 200000,
        ];
    }

    public function validateParams(array $params): array
    {
        $errors = [];

        $threshold = $this->number($params, 'payment_threshold', -1);
        if ($threshold <= 0 || $threshold > 1) {
            $errors[] = 'Порог оплаты задаётся долей личного плана в пределах от 0 до 1 (0,6 — это 60 %).';
        }

        foreach (['rate_p1' => 'П1', 'rate_p2' => 'П2', 'rate_p3' => 'П3', 'rate_k1_per_day' => 'К1'] as $key => $title) {
            if (array_key_exists($key, $params) && $this->number($params, $key, -1) < 0) {
                $errors[] = sprintf('Ставка %s не может быть отрицательной.', $title);
            }
        }

        if ($this->number($params, 'cap', -1) <= 0) {
            $errors[] = 'Предельный размер переменной части должен быть больше нуля.';
        }

        return $errors;
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        $cap = $this->number($params, 'cap', PHP_FLOAT_MAX);

        $parts = [
            'p1' => $this->p1->compute($context, $params),
            'p2' => $this->p2->compute($context, $params),
            'p3' => $this->p3->compute($context, $params),
            'k1' => $this->k1->compute($context, $params),
        ];

        $values = array_map(fn (ComponentResult $r): float => (float) ($r->amount ?? 0.0), $parts);

        $raw = $values['p1'] + $values['p2'] + $values['p3'] - $values['k1'];
        $amount = $this->limit($raw, $cap);

        $warnings = [];
        foreach ($parts as $part) {
            $warnings = array_merge($warnings, $part->warnings);
        }

        if ($context->inputs->motivation === null) {
            $warnings[] = 'Входы переменной части не собраны: показатели считаются нулевыми. Проверьте, что месяц пересчитан по новой схеме.';
        }

        // Эффект показателя: переменная часть без него минус переменная часть с ним.
        $children = [];
        foreach ($parts as $key => $part) {
            $withoutKey = $key === 'k1'
                ? $this->limit($values['p1'] + $values['p2'] + $values['p3'], $cap)
                : $this->limit($raw - $values[$key], $cap);

            $children[] = $part->withEffect(Money::round($amount - $withoutKey));
        }

        return new ComponentResult(
            key: $this->key(),
            label: $this->label(),
            kind: $this->kind(),
            amount: $amount,
            value: $amount,
            explanation: $this->explain($values, $raw, $amount, $cap),
            children: $children,
            warnings: array_values(array_unique($warnings)),
            meta: [
                'p1' => $values['p1'],
                'p2' => $values['p2'],
                'p3' => $values['p3'],
                'k1' => $values['k1'],
                'raw' => Money::round($raw),
                'cap' => $cap,
                'capped' => $raw > $cap,
                'floored' => $raw < 0,
                'plan' => $parts['p1']->meta['plan'] ?? null,
                'threshold' => $parts['p1']->meta['threshold'] ?? null,
            ],
        );
    }

    /**
     * Нижнее ограничение (п. 6.6.1), затем верхнее (п. 6.6.2) — к сумме, а не к слагаемым.
     */
    private function limit(float $raw, float $cap): float
    {
        return Money::round(min($cap, max(0.0, $raw)));
    }

    /**
     * @param  array<string, float>  $values
     */
    private function explain(array $values, float $raw, float $amount, float $cap): string
    {
        $line = sprintf(
            'П1 %s + П2 %s + П3 %s − К1 %s = %s',
            Money::rub($values['p1']), Money::rub($values['p2']),
            Money::rub($values['p3']), Money::rub($values['k1']), Money::rub($raw),
        );

        if ($raw < 0) {
            return $line.sprintf('; вычет превысил показатели — переменная часть %s (ниже нуля не опускается)', Money::rub($amount));
        }

        if ($raw > $cap) {
            return $line.sprintf('; предельный размер %s — к начислению %s', Money::rub($cap), Money::rub($amount));
        }

        return $line;
    }
}

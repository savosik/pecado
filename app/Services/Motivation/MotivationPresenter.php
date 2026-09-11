<?php

namespace App\Services\Motivation;

use App\Models\PayrollCalculation;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\Support\Money;

/**
 * Снимок расчёта в форме экрана «Мой месяц» (карточка mot-26, контракт 04 § 2).
 *
 * Пять строк в порядке Положения, план с порогом, рычаги и прогноз. Перечни,
 * из которых сложилась строка, сюда не входят — их отдаёт отдельный запрос
 * при раскрытии, иначе ответ на менеджера с шестью сотнями документов растёт
 * до сотен килобайт при опросе раз в минуту.
 *
 * Страница и polling-ответ собираются одним презентером: цифры на экране
 * и в фоновом обновлении обязаны совпадать.
 */
class MotivationPresenter
{
    public function __construct(
        private readonly MotivationAdvisor $advisor,
        private readonly PayrollCalculator $calculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(PayrollCalculation $calculation): array
    {
        $breakdownData = (array) $calculation->breakdown;
        $components = array_values(array_filter((array) ($breakdownData['components'] ?? []), 'is_array'));
        $inputs = PayrollInputs::fromArray((array) $calculation->inputs);
        $motivation = $inputs->motivation;

        $variable = $this->find($components, 'motivation_variable');
        $onScheme = $variable !== null;

        $lines = $onScheme ? $this->lines($components, $variable) : [];
        $frozen = $calculation->isFrozen();

        $levers = [];
        $forecast = null;

        // Рычаги и прогноз только у живого черновика текущего месяца: утверждённый
        // месяц не изменить, а прошлому советовать нечего.
        if ($onScheme && ! $frozen && $this->isCurrentMonth($inputs->month)) {
            $params = EffectiveParams::fromArray((array) $calculation->params_effective);
            $current = $this->calculator->calculate($params, $inputs);
            $levers = $this->advisor->levers($params, $inputs, $current);
            $forecast = $this->advisor->forecast($params, $inputs, $current, $levers);
        }

        $warnings = array_values((array) ($breakdownData['warnings'] ?? []));

        return [
            'id' => (int) $calculation->getKey(),
            'status' => $calculation->status,
            'status_label' => $calculation->statusLabel(),
            'frozen' => $frozen,
            'version' => (int) $calculation->version,
            'on_scheme_v2' => $onScheme,
            'computed_at' => $calculation->computed_at?->toIso8601String(),
            'approved_at' => $calculation->approved_at?->toIso8601String(),
            'total' => (float) $calculation->total,
            'lines' => $lines,
            'variable_part' => $variable === null ? null : [
                'amount' => (float) ($variable['amount'] ?? 0),
                'cap' => (float) ($variable['meta']['cap'] ?? 0),
                'capped' => (bool) ($variable['meta']['capped'] ?? false),
                'floored' => (bool) ($variable['meta']['floored'] ?? false),
                'raw' => (float) ($variable['meta']['raw'] ?? 0),
            ],
            'plan' => $this->plan($inputs, $variable),
            'days' => [
                'working_total' => (int) ($inputs->workingDays['total'] ?? 0),
                'working_passed' => (int) ($inputs->workingDays['passed'] ?? 0),
                'worked' => $motivation?->workedDays,
                'absent' => $motivation === null ? null : max(0, $motivation->workingDaysTotal - $motivation->workedDays),
                'substitution' => $motivation->substitutionDays ?? 0,
            ],
            'levers' => $levers,
            'forecast' => $forecast,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Перечень, из которого сложилась строка, — для раскрытия на экране.
     *
     * @return array{line: string, rows: list<array<string, mixed>>, total: float}
     */
    public function evidence(PayrollCalculation $calculation, string $line): array
    {
        $inputs = PayrollInputs::fromArray((array) $calculation->inputs);
        $motivation = $inputs->motivation;

        $rows = match ($line) {
            'base_sales' => $motivation->baseRows ?? [],
            'new_partners' => $motivation->newRows ?? [],
            'focus' => $motivation->focusRows ?? [],
            'overdue' => $motivation->overdueRows ?? [],
            default => [],
        };

        $field = $line === 'overdue' ? 'integral' : 'amount';
        $total = array_sum(array_map(fn (array $row): float => (float) ($row[$field] ?? 0), $rows));

        return ['line' => $line, 'rows' => $rows, 'total' => Money::round($total)];
    }

    /**
     * Пять строк Положения. Постоянная часть слита в одну строку, показатели
     * переменной части — по одному, вычет со знаком минус. Корректировка
     * и доплата до гарантии появляются только когда не равны нулю — тогда
     * сумма строк по-прежнему равна итогу.
     *
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, mixed>  $variable
     * @return list<array<string, mixed>>
     */
    private function lines(array $components, array $variable): array
    {
        $fixed = [];
        $fixedAmount = 0.0;

        foreach (['salary', 'motivation_channels_allowance', 'motivation_substitution'] as $key) {
            $component = $this->find($components, $key);

            if ($component === null) {
                continue;
            }

            $amount = (float) ($component['amount'] ?? 0);
            $fixedAmount += $amount;

            if ($amount > 0 || $key === 'salary') {
                $fixed[] = (string) ($component['explanation'] ?? '');
            }
        }

        $lines = [[
            'key' => 'salary',
            'label' => 'Оклад и надбавки',
            'amount' => Money::round($fixedAmount),
            'description' => 'Постоянная часть: не зависит от выручки, партнёров и просрочек.',
            'how_computed' => 'Оклад плюс надбавка за ведение каналов; надбавка за замещение — по дням табеля.',
            'explanation' => implode('; ', array_filter($fixed)),
            'clause' => '4.1–4.3',
            'evidence_count' => 0,
        ]];

        $children = [];
        foreach ((array) ($variable['children'] ?? []) as $child) {
            if (is_array($child) && isset($child['key'])) {
                $children[(string) $child['key']] = $child;
            }
        }

        $map = [
            'p1' => ['base_sales', 'Продажи своим клиентам', '6.2', 1],
            'p2' => ['new_partners', 'Продажи новым клиентам', '6.3', 1],
            'p3' => ['focus', 'Фокус-товары', '6.4', 1],
            'k1' => ['overdue', 'Минус за просроченные долги', '6.5', -1],
        ];

        foreach ($map as $childKey => [$key, $label, $clause, $sign]) {
            $child = $children[$childKey] ?? null;

            $lines[] = [
                'key' => $key,
                'label' => $label,
                'amount' => Money::round($sign * (float) ($child['amount'] ?? 0)),
                'description' => $this->description($childKey),
                'how_computed' => $this->howComputed($childKey),
                'explanation' => (string) ($child['explanation'] ?? ''),
                'clause' => $clause,
                'evidence_count' => count((array) ($child['evidence'] ?? [])),
                'effect' => isset($child['effect_rub']) ? (float) $child['effect_rub'] : null,
                'meta' => (array) ($child['meta'] ?? []),
            ];
        }

        // Ограничения переменной части — отдельной строкой, чтобы сумма сходилась
        // и было видно, где именно срезано.
        $raw = (float) ($variable['meta']['raw'] ?? 0);
        $amount = (float) ($variable['amount'] ?? 0);
        $limit = Money::round($amount - $raw);

        if (abs($limit) >= 0.01) {
            $lines[] = [
                'key' => 'limit',
                'label' => $raw < 0 ? 'Не ниже нуля' : 'Предельный размер переменной части',
                'amount' => $limit,
                'description' => $raw < 0
                    ? 'Вычет превысил показатели: переменная часть принята равной нулю (п. 6.6.1).'
                    : 'Показатели превысили предельный размер переменной части (п. 6.6.2).',
                'how_computed' => 'Ограничение применяется к сумме показателей, а не к каждому из них.',
                'explanation' => sprintf('Сумма показателей %s, к начислению %s', Money::rub($raw), Money::rub($amount)),
                'clause' => '6.6',
                'evidence_count' => 0,
            ];
        }

        foreach ([
            'manual_correction' => ['correction', 'Корректировка руководителя', '6.7'],
            'motivation_guarantee' => ['guarantee', 'Доплата до гарантии перехода', '12.3'],
        ] as $key => [$lineKey, $label, $clause]) {
            $component = $this->find($components, $key);
            $componentAmount = (float) ($component['amount'] ?? 0);

            if ($component === null || abs($componentAmount) < 0.01) {
                continue;
            }

            $lines[] = [
                'key' => $lineKey,
                'label' => $label,
                'amount' => Money::round($componentAmount),
                'description' => (string) ($component['label'] ?? $label),
                'how_computed' => '',
                'explanation' => (string) ($component['explanation'] ?? ''),
                'clause' => $clause,
                'evidence_count' => 0,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>|null  $variable
     * @return array<string, mixed>
     */
    private function plan(PayrollInputs $inputs, ?array $variable): array
    {
        $motivation = $inputs->motivation;
        $plan = $variable['meta']['plan'] ?? null;
        $threshold = $variable['meta']['threshold'] ?? null;
        $shipped = $motivation->baseRevenue ?? 0.0;

        return [
            'amount' => $plan === null ? null : (float) $plan,
            'original' => $inputs->plan,
            'threshold' => $threshold === null ? null : (float) $threshold,
            'shipped' => Money::round($shipped),
            'percent' => $plan !== null && (float) $plan > 0 ? round($shipped / (float) $plan, 4) : null,
            'remaining' => $plan === null ? null : Money::round(max(0.0, (float) $plan - $shipped)),
            'to_threshold' => $threshold === null ? null : Money::round(max(0.0, (float) $threshold - $shipped)),
            'reduced_by_absence' => (bool) $motivation?->hasAbsence(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed>|null
     */
    private function find(array $components, string $key): ?array
    {
        foreach ($components as $component) {
            if (($component['key'] ?? null) === $key) {
                return $component;
            }
        }

        return null;
    }

    private function isCurrentMonth(string $month): bool
    {
        return $month === now()->startOfMonth()->toDateString();
    }

    private function description(string $key): string
    {
        return match ($key) {
            'p1' => 'Основной показатель: вознаграждение с отгрузок вашей базы сверх порога оплаты.',
            'p2' => 'Повышенная ставка за отгрузки партнёрам в период новизны. Порог оплаты к ней не применяется.',
            'p3' => 'Надбавка за продажи товаров фокус-перечня — дополнительно к П1 и П2 по тем же отгрузкам.',
            'k1' => 'Вычет за долги ваших партнёров: растёт каждый день просрочки и прекращается в день оплаты.',
            default => '',
        };
    }

    private function howComputed(string $key): string
    {
        return match ($key) {
            'p1' => 'Ставка × (отгрузки базы − порог оплаты). Ниже порога — ноль.',
            'p2' => 'Ставка × отгрузки новым партнёрам за вычетом возвратов.',
            'p3' => 'Ставка × отгрузки позиций перечня; у позиции может быть своя ставка.',
            'k1' => 'Ставка за день × сумма «остаток долга × дни просрочки» по каждому документу.',
            default => '',
        };
    }
}

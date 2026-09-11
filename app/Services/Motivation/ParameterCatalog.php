<?php

namespace App\Services\Motivation;

/**
 * Каталог параметров Приложения № 1 (карточка mot-32).
 *
 * Единственное место, где параметр знает свою группу, подпись, тип, пункт
 * Положения и правила связности. Ключи совпадают с config/motivation.php,
 * чтобы умолчания, приказ и экран говорили об одном и том же.
 *
 * Тип — это способ показа и проверки, а не хранения: доли хранятся числом
 * от единицы, деньги — рублями, сроки — целыми днями.
 */
class ParameterCatalog
{
    /**
     * @return list<array{key: string, label: string, params: list<array{key: string, label: string, type: string, clause: string, hint: string}>}>
     */
    public function groups(): array
    {
        return [
            ['key' => 'fixed', 'label' => 'Постоянная часть', 'params' => [
                $this->p('salary', 'Должностной оклад', 'money', '4.1', 'Начисляется независимо от результата. Отклонение по работнику — на вкладке «По работнику»'),
                $this->p('channels_allowance', 'Надбавка за ведение каналов и рассылок', 'money', '4.2', 'Постоянная надбавка тем, кто ведёт корпоративные каналы'),
                $this->p('substitution_per_day', 'Надбавка за замещение, за рабочий день', 'money', '4.3', 'Замещающему — за каждый рабочий день замещения по табелю'),
            ]],
            ['key' => 'variable', 'label' => 'Переменная часть', 'params' => [
                $this->p('payment_threshold', 'Порог оплаты', 'share', '2.14', 'Доля личного плана, до которой П1 не начисляется. П2 и П3 идут с первого рубля'),
                $this->p('rate_p1', 'Ставка П1 — продажи закреплённой базе', 'percent', '6.2', 'От отгрузок базы сверх порога оплаты'),
                $this->p('rate_p2', 'Ставка П2 — продажи новым партнёрам', 'percent', '6.3', 'От отгрузок партнёрам в периоде новизны, без порога'),
                $this->p('rate_p3', 'Ставка П3 — товары фокус-перечня', 'percent', '6.4', 'От отгрузок позиций перечня, дополнительно к П1 и П2'),
                $this->p('rate_k1_per_day', 'Ставка К1 — просроченная задолженность, за день', 'percent_per_day', '6.5', 'От остатка долга за каждый календарный день просрочки'),
                $this->p('novelty_periods', 'Период новизны, расчётных периодов', 'months', '2.10', 'Сколько месяцев отгрузки нового партнёра идут в П2'),
                $this->p('no_purchase_months', 'Срок без закупок для признания новым, месяцев', 'months', '2.9', 'Перерыв, после которого партнёр снова новый'),
                $this->p('grace_working_days', 'Льготный период, рабочих дней', 'days', '2.17', 'После срока оплаты, пока просрочка не начисляется'),
                $this->p('variable_cap', 'Предельный размер переменной части', 'money', '6.6', 'Ограничивает сумму показателей, а не каждый из них'),
                $this->p('adjustment_limit', 'Предел разовой корректировки', 'share', '6.7', 'Доля переменной части, в пределах которой руководитель корректирует расчёт'),
            ]],
            ['key' => 'quarterly', 'label' => 'Квартальная премия отдела', 'params' => [
                $this->p('quarterly_qualification_amount', 'Порог квалификации партнёра за квартал', 'money', '7.3', 'Отгрузки нового партнёра за квартал за вычетом возвратов — по каждому отдельно'),
                $this->p('quarterly_steps', 'Ступени: квалифицированных партнёров → премия', 'steps', '7.4', 'Ступени строго возрастают и по количеству, и по сумме'),
            ]],
            ['key' => 'pool', 'label' => 'Распределение пула', 'params' => [
                $this->p('pool_package_size', 'Предельный размер пакета, партнёров', 'count', '8.2', ''),
                $this->p('pool_contact_working_days', 'Срок фиксации первого контакта, рабочих дней', 'days', '8.3', ''),
                $this->p('pool_shipment_days', 'Срок оформления первой отгрузки, календарных дней', 'days', '8.3', 'Не меньше срока первого контакта'),
                $this->p('pool_tap_periods', 'Периодов подряд ниже порога — выдача останавливается', 'count', '8.4', 'Правило крана'),
            ]],
            ['key' => 'planning', 'label' => 'Планирование', 'params' => [
                $this->p('median_depth_periods', 'Глубина расчёта медианы, расчётных периодов', 'months', '5.2', ''),
                $this->p('overperformance_share', 'Доля учёта перевыполнения прошлого квартала', 'share', '5.4', ''),
                $this->p('growth_rate', 'Целевой прирост', 'share', '5.2', 'Устанавливается приказом на год'),
                $this->p('seasonal', 'Сезонные коэффициенты по месяцам', 'seasonal', '5.2', 'Единица — сезонность не учитывается; задаются на все двенадцать месяцев'),
                $this->p('plan_decline_limit', 'Предел снижения плана за квартал', 'share', 'Прил. 2', 'Решение 08.09.2026: не применяется к первому кварталу новой методики'),
            ]],
            ['key' => 'terms', 'label' => 'Сроки и гарантии', 'params' => [
                $this->p('calculation_day', 'Расчёт представляется к утверждению, число месяца', 'day', '11.2', 'Автоматической заморозки нет: руководитель утверждает руками'),
                $this->p('calculation_data_day', 'По данным на число', 'day', '11.2', ''),
                $this->p('objection_working_days', 'Срок заявления возражений, рабочих дней', 'days', '11.3', 'От даты утверждения расчёта'),
                $this->p('transition_guarantee_share', 'Гарантия переходного периода, доля среднего', 'share', '12.3', 'База среднего фиксируется по работнику на вкладке «По работнику»'),
            ]],
        ];
    }

    /**
     * @return array<string, array{key: string, label: string, type: string, clause: string, hint: string, group: string}>
     */
    public function index(): array
    {
        $index = [];

        foreach ($this->groups() as $group) {
            foreach ($group['params'] as $param) {
                $index[$param['key']] = $param + ['group' => $group['key']];
            }
        }

        return $index;
    }

    /**
     * Полный набор значений: переданные поверх умолчаний Приложения № 1.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function complete(array $values): array
    {
        $defaults = (array) config('motivation.default_parameters', []);
        $result = [];

        foreach (array_keys($this->index()) as $key) {
            $result[$key] = array_key_exists($key, $values) ? $this->normalize($key, $values[$key]) : ($defaults[$key] ?? null);
        }

        return $result;
    }

    /**
     * Правила связности — то, что не выразить типом поля.
     *
     * @param  array<string, mixed>  $values  полный набор
     * @return list<string>
     */
    public function validate(array $values): array
    {
        $errors = [];
        $number = fn (string $key): ?float => is_numeric($values[$key] ?? null) ? (float) $values[$key] : null;

        foreach (['salary', 'channels_allowance', 'substitution_per_day', 'variable_cap', 'quarterly_qualification_amount'] as $key) {
            if ($number($key) === null || $number($key) < 0) {
                $errors[] = sprintf('«%s»: укажите неотрицательную сумму.', $this->label($key));
            }
        }

        if ($number('variable_cap') !== null && $number('variable_cap') <= 0) {
            $errors[] = 'Предельный размер переменной части должен быть больше нуля.';
        }

        $threshold = $number('payment_threshold');
        if ($threshold === null || $threshold <= 0 || $threshold > 1) {
            $errors[] = 'Порог оплаты задаётся долей личного плана от 0 до 1 (0,6 — это 60 %).';
        }

        foreach (['rate_p1', 'rate_p2', 'rate_p3', 'rate_k1_per_day'] as $key) {
            $rate = $number($key);
            if ($rate === null || $rate < 0 || $rate > 1) {
                $errors[] = sprintf('«%s»: ставка задаётся долей от 0 до 1 (0,019 — это 1,9 %%).', $this->label($key));
            }
        }

        foreach (['adjustment_limit', 'overperformance_share', 'plan_decline_limit', 'transition_guarantee_share'] as $key) {
            $share = $number($key);
            if ($share === null || $share < 0 || $share > 1) {
                $errors[] = sprintf('«%s»: доля от 0 до 1.', $this->label($key));
            }
        }

        $growth = $number('growth_rate');
        if ($growth === null || $growth < -0.5 || $growth > 2) {
            $errors[] = 'Целевой прирост задаётся долей: 0,1 — это плюс 10 %. Допустимо от −0,5 до 2.';
        }

        foreach (['novelty_periods', 'no_purchase_months', 'grace_working_days', 'pool_package_size', 'pool_contact_working_days', 'pool_shipment_days', 'pool_tap_periods', 'median_depth_periods', 'objection_working_days'] as $key) {
            $int = $number($key);
            if ($int === null || $int < 0 || floor($int) !== $int) {
                $errors[] = sprintf('«%s»: целое неотрицательное число.', $this->label($key));
            }
        }

        if ($number('novelty_periods') !== null && $number('no_purchase_months') !== null && $number('novelty_periods') > $number('no_purchase_months')) {
            $errors[] = 'Период новизны не может быть длиннее срока без закупок для признания новым.';
        }

        if ($number('pool_shipment_days') !== null && $number('pool_contact_working_days') !== null && $number('pool_shipment_days') < $number('pool_contact_working_days')) {
            $errors[] = 'Срок первой отгрузки не может быть короче срока первого контакта.';
        }

        if (($number('pool_package_size') ?? 0) < 1 || ($number('pool_tap_periods') ?? 0) < 1 || ($number('median_depth_periods') ?? 0) < 1) {
            $errors[] = 'Размер пакета, число периодов крана и глубина медианы — не меньше единицы.';
        }

        foreach (['calculation_day', 'calculation_data_day'] as $key) {
            $day = $number($key);
            if ($day === null || $day < 1 || $day > 28 || floor($day) !== $day) {
                $errors[] = sprintf('«%s»: число месяца от 1 до 28.', $this->label($key));
            }
        }

        $steps = $values['quarterly_steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            $errors[] = 'Ступени квартальной премии не заданы.';
        } else {
            $prevCount = 0;
            $prevAmount = 0.0;
            foreach (array_values($steps) as $i => $step) {
                $count = is_numeric($step['count'] ?? null) ? (int) $step['count'] : null;
                $amount = is_numeric($step['amount'] ?? null) ? (float) $step['amount'] : null;

                if ($count === null || $amount === null || $count <= $prevCount || $amount <= $prevAmount) {
                    $errors[] = sprintf('Ступень %d: количество и сумма должны строго расти от ступени к ступени.', $i + 1);
                    break;
                }

                $prevCount = $count;
                $prevAmount = $amount;
            }
        }

        $seasonal = $values['seasonal'] ?? null;
        if (! is_array($seasonal)) {
            $errors[] = 'Сезонные коэффициенты не заданы.';
        } else {
            for ($m = 1; $m <= 12; $m++) {
                $k = $seasonal[$m] ?? $seasonal[(string) $m] ?? null;
                if (! is_numeric($k) || (float) $k <= 0) {
                    $errors[] = 'Сезонные коэффициенты задаются на все двенадцать месяцев положительными числами.';
                    break;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Что изменилось между двумя наборами — для истории приказов.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{key: string, label: string, from: mixed, to: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($this->index() as $key => $meta) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;

            if (json_encode($from) !== json_encode($to)) {
                $changes[] = ['key' => $key, 'label' => $meta['label'], 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    public function label(string $key): string
    {
        return $this->index()[$key]['label'] ?? $key;
    }

    private function normalize(string $key, mixed $value): mixed
    {
        $type = $this->index()[$key]['type'] ?? 'money';

        return match ($type) {
            'steps' => is_array($value) ? array_values(array_map(fn ($s): array => [
                'count' => is_numeric($s['count'] ?? null) ? (int) $s['count'] : null,
                'amount' => is_numeric($s['amount'] ?? null) ? (float) $s['amount'] : null,
            ], $value)) : $value,
            'seasonal' => is_array($value) ? $this->seasonal($value) : $value,
            'days', 'months', 'count', 'day' => is_numeric($value) ? (int) $value : $value,
            default => is_numeric($value) ? (float) $value : $value,
        };
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int, float>
     */
    private function seasonal(array $value): array
    {
        $result = [];

        for ($m = 1; $m <= 12; $m++) {
            $k = $value[$m] ?? $value[(string) $m] ?? null;
            $result[$m] = is_numeric($k) ? (float) $k : 1.0;
        }

        return $result;
    }

    /**
     * @return array{key: string, label: string, type: string, clause: string, hint: string}
     */
    private function p(string $key, string $label, string $type, string $clause, string $hint): array
    {
        return compact('key', 'label', 'type', 'clause', 'hint');
    }
}

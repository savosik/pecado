<?php

namespace App\Services\Notifications\Pulse;

/**
 * Вычисление условий правила.
 *
 * Дерево вида {"all":[{"field":"status","op":"in","value":["closed"]}]}, где
 * узлы all/any вкладываются друг в друга. Чистый PHP без eval: условие набирает
 * менеджер, и оно лежит в базе — исполнять оттуда код нельзя.
 *
 * Отдельно от полей работают метки (has_tag): они сравниваются целиком, а не
 * как подстрока. Иначе ИНН 7701234567 находился бы внутри 77012345678
 * и внутри номера заказа — источник тихих ложных срабатываний.
 */
class ConditionEvaluator
{
    /**
     * Совпадает ли правило с данными события.
     *
     * NULL или пустое дерево — правило без условий, срабатывает всегда.
     *
     * @param  array<string, mixed>|null  $conditions
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tags
     */
    public function matches(?array $conditions, array $data, array $tags = []): bool
    {
        if (blank($conditions)) {
            return true;
        }

        return $this->evaluateNode($conditions, $data, $tags);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tags
     */
    private function evaluateNode(array $node, array $data, array $tags): bool
    {
        if (isset($node['all']) && is_array($node['all'])) {
            foreach ($node['all'] as $child) {
                if (! is_array($child) || ! $this->evaluateNode($child, $data, $tags)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($node['any']) && is_array($node['any'])) {
            // Пустой any не должен молча пропускать всё: это почти наверняка
            // недособранное правило, и «не совпало» здесь безопаснее.
            if ($node['any'] === []) {
                return false;
            }

            foreach ($node['any'] as $child) {
                if (is_array($child) && $this->evaluateNode($child, $data, $tags)) {
                    return true;
                }
            }

            return false;
        }

        return $this->evaluateLeaf($node, $data, $tags);
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tags
     */
    private function evaluateLeaf(array $leaf, array $data, array $tags): bool
    {
        $op = (string) ($leaf['op'] ?? '=');
        $expected = $leaf['value'] ?? null;

        if ($op === 'has_tag' || $op === 'not_has_tag') {
            $hit = in_array((string) $expected, $tags, true);

            return $op === 'has_tag' ? $hit : ! $hit;
        }

        $field = (string) ($leaf['field'] ?? '');

        if ($field === '') {
            return false;
        }

        $actual = $data[$field] ?? null;

        return $this->compare($actual, $op, $expected);
    }

    private function compare(mixed $actual, string $op, mixed $expected): bool
    {
        return match ($op) {
            '=' => $this->looseEquals($actual, $expected),
            '!=' => ! $this->looseEquals($actual, $expected),
            'in' => is_array($expected) && $this->inList($actual, $expected),
            'not_in' => is_array($expected) && ! $this->inList($actual, $expected),
            '>' => $this->numeric($actual) !== null && $this->numeric($expected) !== null && $this->numeric($actual) > $this->numeric($expected),
            '>=' => $this->numeric($actual) !== null && $this->numeric($expected) !== null && $this->numeric($actual) >= $this->numeric($expected),
            '<' => $this->numeric($actual) !== null && $this->numeric($expected) !== null && $this->numeric($actual) < $this->numeric($expected),
            '<=' => $this->numeric($actual) !== null && $this->numeric($expected) !== null && $this->numeric($actual) <= $this->numeric($expected),
            'between' => $this->between($actual, $expected),
            'contains' => $this->contains($actual, $expected),
            'not_contains' => ! $this->contains($actual, $expected),
            'regex' => $this->regex($actual, $expected),
            'is_empty' => blank($actual),
            'not_empty' => filled($actual),
            default => false,
        };
    }

    /**
     * Сравнение с оглядкой на то, что значения приходят и из JSON, и из БД:
     * «1» и true, «10» и 10 должны считаться равными, а null не равен пустой
     * строке — иначе «поле не заполнено» и «поле равно пустому» слиплись бы.
     */
    private function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return $this->toBool($actual) === $this->toBool($expected);
        }

        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return (string) $actual === (string) $expected;
    }

    /**
     * @param  array<int, mixed>  $list
     */
    private function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $item) {
            if ($this->looseEquals($actual, $item)) {
                return true;
            }
        }

        return false;
    }

    private function between(mixed $actual, mixed $expected): bool
    {
        if (! is_array($expected) || count($expected) !== 2) {
            return false;
        }

        $value = $this->numeric($actual);
        $from = $this->numeric($expected[0]);
        $to = $this->numeric($expected[1]);

        if ($value === null || $from === null || $to === null) {
            return false;
        }

        return $value >= min($from, $to) && $value <= max($from, $to);
    }

    /**
     * Вхождение: для массива — элемент целиком, для строки — подстрока.
     *
     * Массив проверяется точным совпадением элемента намеренно: список
     * изменённых полей заказа не должен ловиться по куску названия.
     */
    private function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return $this->inList($expected, $actual);
        }

        if ($actual === null || $expected === null) {
            return false;
        }

        return str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected));
    }

    /**
     * Регулярное выражение — продвинутый режим фильтра.
     *
     * Выражение набирает человек и хранится в базе, поэтому исполняется
     * с обеих сторон осторожно: разделители ставим сами (иначе пользователь
     * обязан знать про `~`), а кривой шаблон даёт «не совпало», а не падение
     * всего разбора писем.
     */
    private function regex(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || ! is_string($expected) || $expected === '') {
            return false;
        }

        if (is_array($actual)) {
            $actual = implode(' ', array_map(fn ($item): string => (string) $item, $actual));
        }

        $result = @preg_match('~'.str_replace('~', '\~', $expected).'~iu', (string) $actual);

        return $result === 1;
    }

    /**
     * Валиден ли шаблон — чтобы форма могла сказать об ошибке до сохранения,
     * а не после того, как правило молча перестало ловить.
     */
    public static function isValidRegex(string $pattern): bool
    {
        return @preg_match('~'.str_replace('~', '\~', $pattern).'~iu', '') !== false;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower($value), ['1', 'true', 'да', 'yes'], true);
        }

        return (bool) $value;
    }
}

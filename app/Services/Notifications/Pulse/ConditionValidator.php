<?php

namespace App\Services\Notifications\Pulse;

/**
 * Проверка дерева условий против описания события.
 *
 * Один и тот же валидатор работает в форме правила и при загрузке правила
 * движком: правило с невалидным условием не совпадает вовсе и попадает
 * в отчёт «сломанные правила», а не срабатывает наугад.
 *
 * Ошибки возвращаются на русском — их видит менеджер в конструкторе.
 */
class ConditionValidator
{
    /** Глубже трёх уровней условие перестаёт читаться человеком. */
    public const MAX_DEPTH = 3;

    /** Потолок узлов: защита от вставки огромного дерева через API. */
    public const MAX_NODES = 50;

    public function __construct(private readonly NotificationEventRegistry $registry) {}

    /**
     * @param  array<string, mixed>|null  $conditions
     * @return array<int, string> список ошибок; пустой — условие корректно
     */
    public function validate(?array $conditions, string $eventKey): array
    {
        if (blank($conditions)) {
            return [];
        }

        $fields = $this->registry->fieldsFor($eventKey);
        $errors = [];
        $nodes = 0;

        $this->walk($conditions, $fields, 1, $nodes, $errors);

        if ($nodes > self::MAX_NODES) {
            $errors[] = 'Слишком много условий: не больше '.self::MAX_NODES;
        }

        return array_values(array_unique($errors));
    }

    public function passes(?array $conditions, string $eventKey): bool
    {
        return $this->validate($conditions, $eventKey) === [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, \App\Notifications\Pulse\Support\FieldSpec>  $fields
     * @param  array<int, string>  $errors
     */
    private function walk(array $node, array $fields, int $depth, int &$nodes, array &$errors): void
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = 'Слишком глубокая вложенность условий: не больше '.self::MAX_DEPTH.' уровней';

            return;
        }

        foreach (['all', 'any'] as $group) {
            if (isset($node[$group])) {
                if (! is_array($node[$group])) {
                    $errors[] = 'Группа условий задана неверно';

                    return;
                }

                foreach ($node[$group] as $child) {
                    if (! is_array($child)) {
                        $errors[] = 'Условие задано неверно';

                        continue;
                    }

                    $this->walk($child, $fields, $depth + 1, $nodes, $errors);
                }

                return;
            }
        }

        $nodes++;
        $this->validateLeaf($node, $fields, $errors);
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @param  array<string, \App\Notifications\Pulse\Support\FieldSpec>  $fields
     * @param  array<int, string>  $errors
     */
    private function validateLeaf(array $leaf, array $fields, array &$errors): void
    {
        $op = (string) ($leaf['op'] ?? '');

        if ($op === 'has_tag' || $op === 'not_has_tag') {
            if (blank($leaf['value'] ?? null)) {
                $errors[] = 'Укажите метку для условия «содержит»';
            }

            return;
        }

        $field = (string) ($leaf['field'] ?? '');

        if ($field === '') {
            $errors[] = 'В условии не выбрано поле';

            return;
        }

        $spec = $fields[$field] ?? null;

        if ($spec === null) {
            $errors[] = "Поле «{$field}» недоступно для этого события";

            return;
        }

        if (! in_array($op, $spec->operators(), true)) {
            $errors[] = "Условие «{$spec->label}» не поддерживает выбранное сравнение";

            return;
        }

        $this->validateValue($leaf, $spec, $errors);
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @param  array<int, string>  $errors
     */
    private function validateValue(array $leaf, \App\Notifications\Pulse\Support\FieldSpec $spec, array &$errors): void
    {
        $op = (string) ($leaf['op'] ?? '');
        $value = $leaf['value'] ?? null;

        // Операторы наличия значения не требуют
        if (in_array($op, ['is_empty', 'not_empty'], true)) {
            return;
        }

        if ($value === null || $value === '' || $value === []) {
            $errors[] = "Укажите значение для условия «{$spec->label}»";

            return;
        }

        if (in_array($op, ['in', 'not_in'], true) && ! is_array($value)) {
            $errors[] = "Для условия «{$spec->label}» нужно выбрать один или несколько вариантов";

            return;
        }

        if ($op === 'between' && (! is_array($value) || count($value) !== 2)) {
            $errors[] = "Для условия «{$spec->label}» нужно указать оба края диапазона";

            return;
        }

        // Значение из закрытого списка сверяем со списком: опечатка в статусе
        // иначе дала бы молча не работающее правило.
        if ($spec->options !== []) {
            $allowed = array_column($spec->options, 'value');

            foreach ((array) $value as $item) {
                if (! in_array((string) $item, $allowed, true)) {
                    $errors[] = "Недопустимое значение условия «{$spec->label}»";

                    return;
                }
            }
        }
    }
}

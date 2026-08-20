<?php

namespace App\Notifications\Pulse\Support;

/**
 * Описание одного поля события, доступного условиям правила.
 *
 * Тип поля определяет и допустимые операторы, и контрол в конструкторе: для enum
 * рисуется селект со списком из самого перечисления, для числа — поле ввода,
 * для даты — календарь. Варианты значений берутся из enum'ов проекта
 * (OrderStatus, PrintedDocumentType), а не дублируются здесь — иначе список
 * разъедется на первом же новом статусе.
 */
class FieldSpec
{
    /** Строка: сравнение и вхождение подстроки. */
    public const TYPE_STRING = 'string';

    /** Число: сравнения и диапазон. */
    public const TYPE_NUMBER = 'number';

    /** Деньги — то же число, но в интерфейсе с рублями. */
    public const TYPE_MONEY = 'money';

    /** Да/нет. */
    public const TYPE_BOOL = 'bool';

    /** Значение из закрытого списка. */
    public const TYPE_ENUM = 'enum';

    /** Дата. */
    public const TYPE_DATE = 'date';

    /** Массив значений: проверяется вхождение и пустота. */
    public const TYPE_ARRAY = 'array';

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     * @param  array<int, string>|null  $operators
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TYPE_STRING,
        public readonly array $options = [],
        public readonly ?array $operators = null,
        public readonly ?string $hint = null,
    ) {}

    /**
     * Операторы, допустимые для этого поля.
     *
     * Явно заданный список побеждает: у поля может быть свой набор, более узкий
     * чем у типа.
     *
     * @return array<int, string>
     */
    public function operators(): array
    {
        if ($this->operators !== null) {
            return $this->operators;
        }

        return match ($this->type) {
            self::TYPE_NUMBER, self::TYPE_MONEY => ['=', '!=', '>', '>=', '<', '<=', 'between'],
            self::TYPE_DATE => ['=', '!=', '>', '>=', '<', '<=', 'between'],
            self::TYPE_BOOL => ['=', '!='],
            self::TYPE_ENUM => ['=', '!=', 'in', 'not_in'],
            self::TYPE_ARRAY => ['contains', 'not_contains', 'is_empty', 'not_empty'],
            default => ['=', '!=', 'in', 'not_in', 'contains', 'not_contains', 'is_empty', 'not_empty'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => $this->options,
            'operators' => $this->operators(),
            'hint' => $this->hint,
        ];
    }
}

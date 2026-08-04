<?php

namespace App\Services\Crm\Api;

use Illuminate\Validation\Rule;

/**
 * Один аргумент операции CRM-API.
 *
 * Описание для агента и правила проверки живут в одном объекте намеренно: если
 * бы схема для документации и правила валидации лежали по разным файлам, они
 * разошлись бы на первой же правке, и агент строил бы вызов по описанию, которое
 * сервер уже не принимает.
 */
final class Param
{
    /**
     * @param  string  $type  тип JSON Schema: string, integer, number, boolean, array, object
     * @param  list<string|int>  $enum  допустимые значения; пустой — любые
     * @param  list<string>  $rules  дополнительные правила Laravel (max:5000, date и т. п.)
     * @param  string|null  $itemType  тип элемента для array
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description,
        public readonly bool $required = false,
        public readonly array $enum = [],
        public readonly array $rules = [],
        public readonly ?string $itemType = null,
        public readonly bool $nullable = false,
        public readonly mixed $example = null,
    ) {}

    public static function string(string $name, string $description, bool $required = false, array $rules = [], array $enum = [], bool $nullable = false): self
    {
        return new self($name, 'string', $description, $required, $enum, $rules, nullable: $nullable);
    }

    public static function integer(string $name, string $description, bool $required = false, array $rules = [], bool $nullable = false): self
    {
        return new self($name, 'integer', $description, $required, rules: $rules, nullable: $nullable);
    }

    public static function boolean(string $name, string $description, bool $required = false): self
    {
        return new self($name, 'boolean', $description, $required);
    }

    public static function list(string $name, string $description, string $itemType = 'string', bool $required = false): self
    {
        return new self($name, 'array', $description, $required, itemType: $itemType);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $schema = ['type' => $this->type, 'description' => $this->description];

        if ($this->enum !== []) {
            $schema['enum'] = $this->enum;
        }

        if ($this->itemType !== null) {
            $schema['items'] = ['type' => $this->itemType];
        }

        if ($this->example !== null) {
            $schema['example'] = $this->example;
        }

        return $schema;
    }

    /**
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        $rules = [$this->required ? 'required' : 'sometimes'];

        if ($this->nullable) {
            $rules[] = 'nullable';
        }

        $rules[] = match ($this->type) {
            'integer' => 'integer',
            'number' => 'numeric',
            'boolean' => 'boolean',
            'array', 'object' => 'array',
            default => 'string',
        };

        if ($this->enum !== []) {
            $rules[] = Rule::in($this->enum);
        }

        return array_merge($rules, $this->rules);
    }
}

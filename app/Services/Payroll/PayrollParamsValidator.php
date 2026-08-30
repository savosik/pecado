<?php

namespace App\Services\Payroll;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Проверка параметров компонента: JSON Schema компонента плюс его доменные правила.
 *
 * Та же библиотека, что валидирует сообщения 1С (opis/json-schema), — второго
 * валидатора в проекте не заводим.
 */
class PayrollParamsValidator
{
    private readonly Validator $validator;

    public function __construct(private readonly PayrollCatalog $catalog)
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(20);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string> сообщения об ошибках; пусто — параметры годные
     */
    public function validate(string $componentKey, array $params): array
    {
        $component = $this->catalog->component($componentKey);

        $schema = json_decode((string) json_encode($component->paramsSchema()));
        if (! is_object($schema)) {
            return [];
        }
        $schema->{'$id'} = 'https://pecado.local/schemas/payroll/'.$componentKey.'.json';

        $result = $this->validator->validate($this->toJsonValue($params, true), $schema);

        $errors = [];

        if (! $result->isValid() && $result->error() !== null) {
            $formatted = (new ErrorFormatter)->format($result->error(), true);
            foreach ($formatted as $path => $messages) {
                foreach ((array) $messages as $message) {
                    $errors[] = trim($path, '/') === '' ? (string) $message : trim($path, '/').': '.$message;
                }
            }
        }

        return array_values(array_unique(array_merge($errors, $component->validateParams($params))));
    }

    /**
     * PHP-массив в форму, которую понимает opis: ассоциативный массив — объект,
     * список — массив. Пустой массив на верхнем уровне — пустой объект (параметров
     * нет), вложенный пустой — список (пустая лестница).
     */
    private function toJsonValue(mixed $value, bool $top = false): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return $top ? new \stdClass : [];
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->toJsonValue($item), $value);
        }

        $object = new \stdClass;
        foreach ($value as $key => $item) {
            $object->{$key} = $this->toJsonValue($item);
        }

        return $object;
    }
}

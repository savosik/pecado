<?php

namespace App\Services\Client\Api;

use App\Models\User;
use App\Support\OperationApi\Param;

/**
 * Одна операция клиентского API, доступная агенту клиента.
 *
 * Как и в CRM, объект описывает операцию целиком — адрес, гейт, аргументы,
 * обработчик, — и из него растут маршрут REST v1, ответ `/me`, OpenAPI-документ
 * и каталог инструментов MCP. Второго перечня операций нет намеренно.
 *
 * Отличия от операции CRM: вместо права Spatie — фича-гейт раздела кабинета,
 * плюс два флага протокола: идемпотентность (ключ повторного вызова) и
 * контекст юрлица (аргумент `company_id` подставляется по умолчанию).
 */
final class Operation
{
    /**
     * @param  string  $id  идентификатор для агента: 'orders.create'
     * @param  string  $section  раздел каталога: catalog, carts, orders…
     * @param  string  $uri  адрес относительно api/client/v1, параметры пути в фигурных скобках
     * @param  list<Param>  $params
     * @param  array{class-string, string}  $handler  обработчик: [класс, метод]
     * @param  bool  $mutating  меняет данные — попадает в аудит
     * @param  FeatureGate  $gate  раздел кабинета, который должен быть открыт клиенту
     * @param  bool  $idempotent  принимает ключ идемпотентности
     * @param  bool  $idempotencyRequired  без ключа не выполняется (создание заказа)
     * @param  bool  $companyScoped  требует контекст юрлица: `company_id` с умолчанием
     * @param  bool  $agentAllowed  доступна агенту; false — видна в каталоге, но не выполняется
     * @param  string|null  $deniedReason  почему закрыта, если $agentAllowed = false
     */
    public function __construct(
        public readonly string $id,
        public readonly string $section,
        public readonly string $method,
        public readonly string $uri,
        public readonly string $summary,
        public readonly string $description,
        public readonly array $params,
        public readonly array $handler,
        public readonly bool $mutating = false,
        public readonly FeatureGate $gate = FeatureGate::NONE,
        public readonly bool $idempotent = false,
        public readonly bool $idempotencyRequired = false,
        public readonly bool $companyScoped = false,
        public readonly bool $agentAllowed = true,
        public readonly ?string $deniedReason = null,
    ) {}

    /**
     * Параметры пути, вынутые из адреса: 'orders/{order}/cancel' → ['order'].
     *
     * @return list<string>
     */
    public function pathParams(): array
    {
        preg_match_all('/\{(\w+)\}/', $this->uri, $matches);

        return $matches[1];
    }

    /**
     * Полный адрес REST-варианта операции.
     */
    public function path(): string
    {
        return '/api/client/v1/'.$this->uri;
    }

    /**
     * Ограничения параметров пути для маршрута: числовые id — только цифры,
     * идентификаторы товара/документа — безопасный набор символов. Иначе
     * `orders/changes` ушло бы в карточку заказа с id «changes».
     *
     * @return array<string, string>
     */
    public function routeConstraints(): array
    {
        $constraints = [];

        foreach ($this->pathParams() as $name) {
            $param = $this->param($name);

            $constraints[$name] = $param?->type === 'integer'
                ? '[0-9]+'
                : '[A-Za-z0-9._\-]+';
        }

        return $constraints;
    }

    public function param(string $name): ?Param
    {
        foreach ($this->params as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->params as $param) {
            $rules[$param->name] = $param->validationRules();
        }

        return $rules;
    }

    /**
     * Русские имена полей для сообщений валидатора.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->params as $param) {
            $attributes[$param->name] = mb_strtolower($param->description);
        }

        return $attributes;
    }

    /**
     * JSON Schema аргументов — то, что видит агент в describe и в OpenAPI.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->params as $param) {
            $properties[$param->name] = $param->jsonSchema();

            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ], fn ($value) => $value !== []);
    }

    /**
     * Доступна ли операция этому клиенту прямо сейчас.
     */
    public function allowedFor(User $actor): bool
    {
        return $this->agentAllowed && $this->gate->allows($actor);
    }

    /**
     * Причина недоступности — текст для агента, null если доступна.
     */
    public function deniedReasonFor(User $actor): ?string
    {
        if (! $this->agentAllowed) {
            return $this->deniedReason ?? 'Операция «'.$this->id.'» недоступна через API.';
        }

        if (! $this->gate->allows($actor)) {
            return $this->gate->reason();
        }

        return null;
    }

    /**
     * Строка каталога: то, по чему агент решает, что вызывать.
     *
     * @return array<string, mixed>
     */
    public function catalogEntry(User $actor): array
    {
        $allowed = $this->allowedFor($actor);

        return array_filter([
            'id' => $this->id,
            'section' => $this->section,
            'method' => $this->method,
            'path' => $this->path(),
            'summary' => $this->summary,
            'mutating' => $this->mutating,
            'idempotent' => $this->idempotent,
            'idempotency_required' => $this->idempotencyRequired,
            'company_scoped' => $this->companyScoped,
            'gate' => $this->gate === FeatureGate::NONE ? null : $this->gate->value,
            'allowed' => $allowed,
            'denied_code' => $allowed ? null : ($this->agentAllowed ? $this->gate->code() : 'operation_denied'),
            'denied_reason' => $this->deniedReasonFor($actor),
        ], fn ($value) => $value !== null);
    }
}

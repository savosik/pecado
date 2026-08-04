<?php

namespace App\Services\Crm\Api;

use App\Models\User;

/**
 * Одна операция CRM, доступная машинному потребителю.
 *
 * Объект описывает операцию целиком — адрес, право, аргументы и обработчик, —
 * поэтому из него растут сразу три вещи: маршрут REST, ответ discovery-метода
 * и каталог инструментов MCP. Второго списка эндпоинтов в проекте нет, и
 * документация не может разойтись с тем, что сервер реально принимает.
 */
final class Operation
{
    /**
     * @param  string  $id  идентификатор для агента: 'client.comment.create'
     * @param  string  $section  раздел каталога: clients, tasks, plans…
     * @param  string  $uri  адрес относительно api/crm, с параметрами пути в фигурных скобках
     * @param  list<Param>  $params
     * @param  array{class-string, string}  $handler  обработчик: [класс, метод]
     * @param  bool  $mutating  меняет данные — попадает в аудит
     * @param  bool  $agentAllowed  доступна агенту; false — видна в каталоге, но не выполняется
     * @param  string|null  $deniedReason  почему запрещена, если $agentAllowed = false
     */
    public function __construct(
        public readonly string $id,
        public readonly string $section,
        public readonly string $method,
        public readonly string $uri,
        public readonly string $permission,
        public readonly string $summary,
        public readonly string $description,
        public readonly array $params,
        public readonly array $handler,
        public readonly bool $mutating = false,
        public readonly bool $agentAllowed = true,
        public readonly ?string $deniedReason = null,
    ) {}

    /**
     * Параметры пути, вынутые из адреса: 'clients/{client}/profile' → ['client'].
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
        return '/api/crm/'.$this->uri;
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
     * Русские имена полей для сообщений валидатора: без них агент получил бы
     * «поле client_id обязательно» вперемешку с человеческими формулировками.
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
     * JSON Schema аргументов — то, что видит агент в crm-describe и в OpenAPI.
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
     * Доступна ли операция конкретному сотруднику прямо сейчас.
     */
    public function allowedFor(User $actor): bool
    {
        return $this->agentAllowed && $actor->can($this->permission);
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
            'permission' => $this->permission,
            'mutating' => $this->mutating,
            'allowed' => $allowed,
            'denied_reason' => $allowed ? null : ($this->deniedReason ?? 'Нет права «'.$this->permission.'».'),
        ], fn ($value) => $value !== null);
    }
}

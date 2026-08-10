<?php

namespace App\Services\Crm\Api;

/**
 * OpenAPI-документ CRM API, собранный из реестра операций.
 *
 * Документ строится обходом того же {@see OperationRegistry}, из которого
 * собраны маршруты, — поэтому «в документации есть, а сервер не принимает»
 * здесь невозможно по построению. Автоматический разбор контроллеров
 * (Scramble) сюда не подходит: контроллер один на все операции, и вывести
 * из его сигнатуры аргументы трёх десятков разных вызовов нельзя — а в реестре
 * они уже описаны, вместе с правилами проверки.
 */
class CrmApiDocument
{
    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Pecado CRM API',
                'version' => '1.0',
                'description' => $this->description(),
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/')]],
            'tags' => $this->tags(),
            'components' => [
                'securitySchemes' => [
                    'bearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Токен ИИ-агента менеджера. Выдаётся руководителем отдела '
                            .'на экране «Токены ИИ-агентов» или командой `php artisan crm:token issue`.',
                    ],
                ],
            ],
            'security' => [['bearer' => []]],
            'paths' => $this->paths(),
        ];
    }

    /**
     * Бизнес-правила, без которых агент построит правдоподобный и неверный запрос.
     */
    private function description(): string
    {
        return implode("\n\n", [
            'API отдела продаж для ИИ-агентов менеджеров. Токен превращается в конкретного '
                .'сотрудника: операции идут его правами, и набор партнёров всегда ограничен его скоупом.',
            '## Что нужно знать заранее',
            '**Партнёр** — пользователь с непустым персональным менеджером. Без менеджера это лид, '
                .'он живёт в админке, а не в CRM отдела.',
            '**Факт продаж** — отгрузки по дате документа в 1С (`erp_created_at`), а не по `created_at`: '
                .'историю заказов импортировали в мае 2026, поэтому отчёт по `created_at` будет неверным '
                .'и при этом выглядеть правдоподобно.',
            '**Лояльностью партнёра владеет 1С** и перезаписывает её следующим обменом. Через API меняется '
                .'только жизненный статус — поле сайта, которое ведёт отдел продаж.',
            '**Чужой партнёр даёт 404, а не 403** — существование записи вне скоупа не подтверждается.',
            '## Начните с `GET /api/crm/me`',
            'Он отдаёт актора, его права, размер скоупа и полный каталог операций с флагом `allowed`. '
                .'Каталог строится из того же реестра, что и маршруты, поэтому не может разойтись с тем, '
                .'что сервер принимает.',
            '## Записи агента помечаются',
            'Всё, что создано через этот гейт, получает `source = agent`: в ленте партнёра видно, что '
                .'комментарий или письмо сделал агент, а не человек.',
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function tags(): array
    {
        $tags = [];

        foreach ($this->registry->sections() as $name => $label) {
            $tags[] = ['name' => $label, 'description' => 'Раздел «'.$label.'» (section = '.$name.')'];
        }

        return $tags;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function paths(): array
    {
        $paths = [
            '/api/crm/me' => [
                'get' => [
                    'operationId' => 'me',
                    'tags' => ['Партнёры'],
                    'summary' => 'Кто я и что мне доступно',
                    'description' => 'Актор, его права, размер скоупа и каталог операций с флагом `allowed`.',
                    'responses' => $this->responses(),
                ],
            ],
        ];

        $sections = $this->registry->sections();

        foreach ($this->registry->callable() as $operation) {
            $path = $operation->path();
            $method = mb_strtolower($operation->method);

            $entry = [
                'operationId' => $operation->id,
                'tags' => [$sections[$operation->section] ?? $operation->section],
                'summary' => $operation->summary,
                'description' => $operation->description
                    .($operation->mutating ? "\n\nОперация записи: попадает в аудит-журнал агентского гейта." : ''),
                'responses' => $this->responses(),
            ];

            $parameters = $this->parameters($operation);

            if ($parameters !== []) {
                $entry['parameters'] = $parameters;
            }

            $body = $this->requestBody($operation);

            if ($body !== null) {
                $entry['requestBody'] = $body;
            }

            $paths[$path][$method] = $entry;
        }

        return $paths;
    }

    /**
     * Параметры пути — всегда; параметры запроса — только у GET и DELETE,
     * у остальных они уезжают в тело.
     *
     * @return list<array<string, mixed>>
     */
    private function parameters(Operation $operation): array
    {
        $pathParams = $operation->pathParams();
        $inQuery = in_array($operation->method, ['GET', 'DELETE'], true);
        $parameters = [];

        foreach ($operation->params as $param) {
            $isPath = in_array($param->name, $pathParams, true);

            if (! $isPath && ! $inQuery) {
                continue;
            }

            $parameters[] = [
                'name' => $param->name,
                'in' => $isPath ? 'path' : 'query',
                'required' => $isPath || $param->required,
                'description' => $param->description,
                'schema' => $param->jsonSchema(),
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(Operation $operation): ?array
    {
        if (in_array($operation->method, ['GET', 'DELETE'], true)) {
            return null;
        }

        $pathParams = $operation->pathParams();
        $properties = [];
        $required = [];

        foreach ($operation->params as $param) {
            if (in_array($param->name, $pathParams, true)) {
                continue;
            }

            $properties[$param->name] = $param->jsonSchema();

            if ($param->required) {
                $required[] = $param->name;
            }
        }

        if ($properties === []) {
            return null;
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return [
            'required' => $required !== [],
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * Один набор ответов на все операции: тело результата у каждой своё, а вот
     * коды отказов общие — их задаёт гейт, а не операция.
     *
     * @return array<int|string, array<string, string>>
     */
    private function responses(): array
    {
        return [
            '200' => ['description' => 'Результат операции'],
            '401' => ['description' => 'Токен недействителен или отозван'],
            '403' => ['description' => 'Нет права на операцию либо она закрыта для агента'],
            '404' => ['description' => 'Запись не найдена или вне скоупа сотрудника'],
            '422' => ['description' => 'Аргументы не прошли проверку либо бизнес-правило отказало'],
            '429' => ['description' => 'Превышен лимит обращений'],
        ];
    }
}

<?php

namespace App\Services\Client\Api;

/**
 * OpenAPI-документ клиентского API v1, собранный из реестра операций.
 *
 * Как и у CRM, документ строится обходом того же {@see OperationRegistry},
 * из которого собраны маршруты и каталог `/me`, — «в документации есть,
 * а сервер не принимает» невозможно по построению. Автоматический разбор
 * контроллеров (Scramble) не подходит: контроллер один на все операции.
 *
 * Сверх CRM-образца документ описывает протокольные особенности клиента:
 * контекст юрлица (`company_id`), ключ идемпотентности и фича-гейты разделов.
 */
class ClientApiDocument
{
    /** Имя общего параметра-заголовка идемпотентности в components. */
    public const IDEMPOTENCY_PARAMETER = 'IdempotencyKey';

    /** Имя общего параметра юрлица в components. */
    public const COMPANY_PARAMETER = 'CompanyId';

    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Pecado Client API',
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
                        'description' => 'API-ключ клиента из личного кабинета (раздел «API», `/api-tokens`). '
                            .'Тот же ключ, что подставляется в адрес legacy `/api/client-api/{token}/*`, '
                            .'здесь передаётся в заголовке `Authorization: Bearer <ключ>`.',
                    ],
                ],
                'parameters' => [
                    self::IDEMPOTENCY_PARAMETER => [
                        'name' => 'Idempotency-Key',
                        'in' => 'header',
                        'required' => false,
                        'description' => 'Ключ идемпотентности — уникальная строка запроса (например, UUID). '
                            .'Повтор с тем же ключом и тем же телом возвращает прежний ответ, ничего не создавая; '
                            .'тот же ключ с другим телом — 422 `idempotency_key_reused`; '
                            .'параллельный запрос с тем же ключом — 409 `idempotency_in_progress`. '
                            .'На создании заказа заголовок обязателен.',
                        'schema' => ['type' => 'string', 'maxLength' => 255],
                    ],
                    self::COMPANY_PARAMETER => [
                        'name' => 'company_id',
                        'in' => 'query',
                        'required' => false,
                        'description' => 'Юрлицо клиента (`companies[].id` из `/me`). Можно не передавать, если у клиента '
                            .'одно юрлицо или назначено основное; при нескольких без основного — 422 `company_required` '
                            .'с перечнем `meta.companies`.',
                        'schema' => ['type' => 'integer'],
                    ],
                ],
                'schemas' => [
                    'Envelope' => [
                        'type' => 'object',
                        'description' => 'Успешный ответ: результат в `data`, служебные сведения в `meta`.',
                        'properties' => [
                            'data' => ['description' => 'Результат операции: объект или список.'],
                            'meta' => ['type' => 'object', 'description' => 'Служебные сведения (курсоры, признаки создания, предупреждения).'],
                        ],
                        'required' => ['data'],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'description' => 'Ответ с ошибкой: список ошибок с машиночитаемым кодом и русским текстом.',
                        'properties' => [
                            'errors' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'code' => ['type' => 'string', 'description' => 'Код ошибки: validation, not_found, company_required, idempotency_key_reused и т. д.'],
                                        'message' => ['type' => 'string', 'description' => 'Текст для человека, на русском.'],
                                        'field' => ['type' => 'string', 'description' => 'Поле запроса, к которому относится ошибка (если есть).'],
                                    ],
                                    'required' => ['code', 'message'],
                                ],
                            ],
                            'meta' => ['type' => 'object', 'description' => 'Дополнения к ошибке: перечень юрлиц, конфликты остатков, сведения о долге.'],
                        ],
                        'required' => ['errors'],
                    ],
                    'CursorMeta' => [
                        'type' => 'object',
                        'description' => 'Мета курсорной страницы. Следующую страницу запрашивают параметром `cursor` = `next_cursor`.',
                        'properties' => [
                            'per_page' => ['type' => 'integer', 'description' => 'Размер страницы.'],
                            'has_more' => ['type' => 'boolean', 'description' => 'Есть ли записи дальше.'],
                            'next_cursor' => ['type' => ['string', 'null'], 'description' => 'Курсор следующей страницы; null — страница последняя.'],
                            'prev_cursor' => ['type' => ['string', 'null'], 'description' => 'Курсор предыдущей страницы; null — страница первая.'],
                        ],
                    ],
                ],
            ],
            'security' => [['bearer' => []]],
            'paths' => $this->paths(),
        ];
    }

    /**
     * Правила протокола, без которых агент построит правдоподобный и неверный запрос.
     */
    private function description(): string
    {
        return implode("\n\n", [
            'API личного кабинета клиента Pecado для собственных систем и ИИ-агентов покупателя: '
                .'каталог с индивидуальными ценами и остатками, корзины, заказы, реализации, документы и оплаты. '
                .'Токен превращается в конкретного клиента: все операции идут от его имени и ограничены его данными.',
            '## Начните с `GET /api/client/v1/me`',
            'Он отдаёт клиента, его юрлица, состояние разделов кабинета (`features`) и полный каталог операций '
                .'с флагом `allowed`. Каталог строится из того же реестра, что и маршруты, поэтому не может '
                .'разойтись с тем, что сервер принимает.',
            '## Юрлицо (`company_id`)',
            'У клиента может быть несколько юрлиц. Операции, привязанные к юрлицу, принимают `company_id` '
                .'(`companies[].id` из `/me`). Его можно не передавать: подставится основное юрлицо или единственное. '
                .'Если юрлиц несколько и основное не назначено — 422 `company_required` с перечнем в `meta.companies`.',
            '## Идемпотентность',
            'Пишущие операции принимают заголовок `Idempotency-Key` — уникальную строку запроса (UUID). '
                .'На создании заказа он **обязателен**. Повтор с тем же ключом и тем же телом возвращает прежний ответ '
                .'и ничего не создаёт; тот же ключ с другим телом — 422 `idempotency_key_reused`; '
                .'параллельный запрос с тем же ключом — 409 `idempotency_in_progress`.',
            '## Разделы кабинета (фича-гейты)',
            'Часть разделов открывается клиенту флагами (документы, оплаты, договоры, режим резервов, отмена заказа). '
                .'Операция выключенного раздела отвечает 403 с кодом гейта: '
                .implode(', ', array_map(
                    fn (FeatureGate $gate) => '`'.$gate->code().'`',
                    array_filter(FeatureGate::cases(), fn (FeatureGate $gate) => $gate !== FeatureGate::NONE),
                )).'. Смотрите `features` и `allowed` в `/me` до вызова.',
            '## Конверт ответа',
            'Успех — `{data, meta}`; ошибка — `{errors: [{code, message, field}], meta}`. Один формат на все операции: '
                .'агент разбирает ответ одним правилом. Тексты `message` — на русском, для человека; решения принимайте по `code`.',
            '## Пагинация',
            'Списки отдаются курсором: параметры `cursor` и `per_page`, в ответе `meta.next_cursor` и `meta.has_more`. '
                .'Следующая страница — тот же запрос с `cursor = meta.next_cursor`. Потолок `per_page` — '
                .Envelope::PER_PAGE_MAX.' (у фидов цен и остатков — '.Envelope::PER_PAGE_MAX_FEED.').',
            '## Чужие записи',
            'Запись другого клиента даёт 404, а не 403 — существование чужих данных не подтверждается.',
            '## Legacy API',
            'Прежний `/api/client-api/{token}/*` (ключ в адресе) поддерживается бессрочно, но не развивается: '
                .'новые возможности появляются только здесь.',
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
        $sections = $this->registry->sections();

        $paths = [
            '/api/client/v1/me' => [
                'get' => [
                    'operationId' => 'me',
                    'tags' => [$sections['profile'] ?? 'Профиль'],
                    'summary' => 'Кто я и что мне доступно',
                    'description' => 'Клиент, его юрлица, состояние разделов кабинета, каталог операций с флагом `allowed`, '
                        .'адреса документации и лимиты.',
                    'responses' => $this->responses(),
                ],
            ],
        ];

        foreach ($this->registry->callable() as $operation) {
            $path = $operation->path();
            $method = mb_strtolower($operation->method);

            $entry = [
                'operationId' => $operation->id,
                'tags' => [$sections[$operation->section] ?? $operation->section],
                'summary' => $operation->summary,
                'description' => $this->operationDescription($operation),
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
     * Описание операции с протокольными оговорками: гейт, идемпотентность, аудит.
     */
    private function operationDescription(Operation $operation): string
    {
        $lines = [$operation->description];

        if ($operation->gate !== FeatureGate::NONE) {
            $lines[] = 'Раздел: `'.$operation->gate->value.'` — при выключенном разделе 403 `'.$operation->gate->code().'`.';
        }

        if ($operation->idempotent) {
            $lines[] = $operation->idempotencyRequired
                ? 'Обязателен заголовок Idempotency-Key.'
                : 'Принимает Idempotency-Key.';
        }

        if ($operation->companyScoped) {
            $lines[] = 'Привязана к юрлицу: `company_id` с умолчанием — основное или единственное юрлицо.';
        }

        if ($operation->mutating) {
            $lines[] = 'Операция записи: попадает в аудит.';
        }

        return implode("\n\n", $lines);
    }

    /**
     * Параметры пути — всегда; параметры запроса — только у GET и DELETE,
     * у остальных они уезжают в тело. Плюс общие ссылки: заголовок
     * идемпотентности и `company_id` для GET-операций юрлица.
     *
     * @return list<array<string, mixed>>
     */
    private function parameters(Operation $operation): array
    {
        $pathParams = $operation->pathParams();
        $inQuery = $this->paramsInQuery($operation);
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

        if ($operation->companyScoped && $inQuery && $operation->param('company_id') === null) {
            $parameters[] = ['$ref' => '#/components/parameters/'.self::COMPANY_PARAMETER];
        }

        if ($operation->idempotent) {
            $parameters[] = ['$ref' => '#/components/parameters/'.self::IDEMPOTENCY_PARAMETER];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(Operation $operation): ?array
    {
        if ($this->paramsInQuery($operation)) {
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

        if ($operation->companyScoped && ! isset($properties['company_id'])) {
            $properties['company_id'] = [
                'type' => 'integer',
                'description' => 'Юрлицо клиента (`companies[].id` из `/me`). Без него — основное или единственное; '
                    .'при нескольких без основного — 422 `company_required`.',
            ];
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

    private function paramsInQuery(Operation $operation): bool
    {
        return in_array($operation->method, ['GET', 'DELETE'], true);
    }

    /**
     * Один набор ответов на все операции: тело результата у каждой своё, а
     * коды отказов общие — их задаёт протокол, а не операция.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function responses(): array
    {
        $error = ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];

        return [
            '200' => [
                'description' => 'Результат операции в конверте `{data, meta}`',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
            ],
            '401' => ['description' => 'Токен не передан, недействителен или отозван'] + $error,
            '403' => ['description' => 'Раздел кабинета выключен для клиента (код гейта в `errors[].code`) либо операция закрыта для API'] + $error,
            '404' => ['description' => 'Запись не найдена или принадлежит другому клиенту'] + $error,
            '409' => ['description' => 'Конфликт: устарела версия состава заказа, изменились остатки либо запрос с этим ключом идемпотентности ещё выполняется'] + $error,
            '422' => ['description' => 'Аргументы не прошли проверку, не выбрано юрлицо, повторно использован ключ идемпотентности либо бизнес-правило отказало'] + $error,
            '429' => ['description' => 'Превышен лимит обращений (60 запросов в минуту на ключ)'] + $error,
        ];
    }
}

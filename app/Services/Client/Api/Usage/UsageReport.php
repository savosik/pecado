<?php

namespace App\Services\Client\Api\Usage;

use App\Enums\Crm\CrmScope;
use App\Models\ApiToken;
use App\Models\ClientAgentCall;
use App\Models\User;
use App\Services\Client\Api\OperationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Экран «ИИ-агенты клиентов»: кто из партнёров пользуется своим агентом, как
 * часто и для чего, — по журналу `client_agent_calls`.
 *
 * Видимость партнёров — та же, что у остальных разделов CRM: разрез «мои /
 * весь отдел» ({@see CrmScope}) поверх закрепления за менеджером. Аргументов
 * и ответов в журнале нет, поэтому отчёт отвечает на «сколько и что», а не
 * «что именно заказали» — за этим карточка партнёра.
 */
final class UsageReport
{
    /** Периоды экрана в днях; первый — умолчание. */
    public const PERIODS = [30, 7, 90, 365];

    /** Операции, по которым судят о пользе: агент довёл дело до заказа или вопроса. */
    private const ORDER_OPERATIONS = ['orders.create', 'checkout.submit'];

    private const QUESTION_OPERATIONS = ['questions.create'];

    /** Человекочитаемые имена инструментов MCP (описания у них длинные — это подсказки агенту). */
    private const TOOL_LABELS = [
        'client-catalog' => 'Каталог операций',
        'client-describe' => 'Схема операции',
        'client-call' => 'Вызов операции',
        'client-prices' => 'Цены и остатки',
        'client-order-status' => 'Статус заказа',
        'client-create-order' => 'Создание заказа',
        'client-balance' => 'Баланс и платежи',
        'client-documents' => 'Документы',
        'client-promotions' => 'Акции',
        'client-faq' => 'FAQ и страницы сайта',
        'client-ask-manager' => 'Вопрос менеджеру',
    ];

    public function __construct(private readonly OperationRegistry $registry) {}

    public static function period(mixed $value): int
    {
        $days = (int) $value;

        return in_array($days, self::PERIODS, true) ? $days : self::PERIODS[0];
    }

    public static function since(int $days): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays($days - 1);
    }

    /**
     * Партнёры, которых видит сотрудник в выбранном разрезе.
     *
     * @return Builder<User>
     */
    public function visibleClients(User $actor, CrmScope $scope): Builder
    {
        $query = User::query()->clients();

        if (! $actor->can('crm-department.view') || $scope->isMine()) {
            $managerId = $actor->managerProfile?->id;

            return $managerId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('personal_manager_id', $managerId);
        }

        if ($actor->crm_show_unassigned) {
            $query->whereNull('personal_manager_id');
        }

        return $query;
    }

    /**
     * Сводка периода: охват, объём, польза.
     *
     * @param  Builder<User>  $clients
     * @return array<string, int|float>
     */
    public function summary(Builder $clients, CarbonImmutable $since): array
    {
        $calls = $this->calls($clients, $since);

        $row = (clone $calls)->toBase()->selectRaw(implode(', ', [
            'COUNT(DISTINCT user_id) AS partners',
            'COUNT(DISTINCT session_id) AS sessions',
            "SUM(CASE WHEN kind = 'mcp_connect' THEN 1 ELSE 0 END) AS connects",
            "SUM(CASE WHEN kind = 'mcp_tool' THEN 1 ELSE 0 END) AS tool_calls",
            "SUM(CASE WHEN kind = 'rest' THEN 1 ELSE 0 END) AS rest_calls",
            "SUM(CASE WHEN kind <> 'mcp_connect' AND ok = 0 THEN 1 ELSE 0 END) AS errors",
            "SUM(CASE WHEN kind <> 'mcp_connect' AND ok = 1 AND operation IN ('".implode("','", self::ORDER_OPERATIONS)."') THEN 1 ELSE 0 END) AS orders",
            "SUM(CASE WHEN kind <> 'mcp_connect' AND ok = 1 AND operation IN ('".implode("','", self::QUESTION_OPERATIONS)."') THEN 1 ELSE 0 END) AS questions",
        ]))->first();

        $toolCalls = (int) ($row->tool_calls ?? 0);
        $restCalls = (int) ($row->rest_calls ?? 0);
        $errors = (int) ($row->errors ?? 0);
        $requests = $toolCalls + $restCalls;

        return [
            'partners' => (int) ($row->partners ?? 0),
            'partners_with_tokens' => $this->activeTokens($clients)->distinct()->count('user_id'),
            'sessions' => (int) ($row->sessions ?? 0),
            'connects' => (int) ($row->connects ?? 0),
            'tool_calls' => $toolCalls,
            'rest_calls' => $restCalls,
            'requests' => $requests,
            'errors' => $errors,
            'error_rate' => $requests > 0 ? round($errors * 100 / $requests, 1) : 0.0,
            'orders' => (int) ($row->orders ?? 0),
            'questions' => (int) ($row->questions ?? 0),
        ];
    }

    /**
     * Вызовы по дням для графика; дни без вызовов — нулями, чтобы тишина была видна.
     *
     * @param  Builder<User>  $clients
     * @return list<array{date: string, tool_calls: int, rest_calls: int, connects: int, errors: int}>
     */
    public function daily(Builder $clients, CarbonImmutable $since, int $days): array
    {
        $rows = $this->calls($clients, $since)
            ->toBase()
            ->selectRaw(implode(', ', [
                'DATE(created_at) AS day',
                "SUM(CASE WHEN kind = 'mcp_tool' THEN 1 ELSE 0 END) AS tool_calls",
                "SUM(CASE WHEN kind = 'rest' THEN 1 ELSE 0 END) AS rest_calls",
                "SUM(CASE WHEN kind = 'mcp_connect' THEN 1 ELSE 0 END) AS connects",
                "SUM(CASE WHEN kind <> 'mcp_connect' AND ok = 0 THEN 1 ELSE 0 END) AS errors",
            ]))
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $since->addDays($i)->toDateString();
            $row = $rows->get($day);

            $series[] = [
                'date' => $day,
                'tool_calls' => (int) ($row->tool_calls ?? 0),
                'rest_calls' => (int) ($row->rest_calls ?? 0),
                'connects' => (int) ($row->connects ?? 0),
                'errors' => (int) ($row->errors ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Партнёры с вызовами за период — по убыванию активности.
     *
     * @param  Builder<User>  $clients
     * @return list<array<string, mixed>>
     */
    public function partners(Builder $clients, CarbonImmutable $since, int $limit = 200): array
    {
        $rows = $this->calls($clients, $since)
            ->toBase()
            ->selectRaw(implode(', ', [
                'user_id',
                "SUM(CASE WHEN kind = 'mcp_connect' THEN 1 ELSE 0 END) AS connects",
                "SUM(CASE WHEN kind = 'mcp_tool' THEN 1 ELSE 0 END) AS tool_calls",
                "SUM(CASE WHEN kind = 'rest' THEN 1 ELSE 0 END) AS rest_calls",
                "SUM(CASE WHEN kind <> 'mcp_connect' AND ok = 0 THEN 1 ELSE 0 END) AS errors",
                "SUM(CASE WHEN kind <> 'mcp_connect' AND ok = 1 AND operation IN ('".implode("','", self::ORDER_OPERATIONS)."') THEN 1 ELSE 0 END) AS orders",
                "SUM(CASE WHEN kind <> 'mcp_connect' AND ok = 1 AND operation IN ('".implode("','", self::QUESTION_OPERATIONS)."') THEN 1 ELSE 0 END) AS questions",
                'COUNT(DISTINCT session_id) AS sessions',
                'MIN(created_at) AS first_at',
                'MAX(created_at) AS last_at',
            ]))
            ->groupBy('user_id')
            ->orderByRaw("SUM(CASE WHEN kind <> 'mcp_connect' THEN 1 ELSE 0 END) DESC")
            ->orderByDesc('last_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $userIds = $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->with('personalManager:id,name')
            ->get(['id', 'name', 'erp_name', 'personal_manager_id'])
            ->keyBy('id');

        $agents = $this->agentsByUser($userIds, $since);

        $tokens = ApiToken::query()
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->selectRaw('user_id, COUNT(*) AS n')
            ->groupBy('user_id')
            ->pluck('n', 'user_id');

        return $rows->map(function ($row) use ($users, $agents, $tokens): array {
            $user = $users->get((int) $row->user_id);

            return [
                'id' => (int) $row->user_id,
                'name' => $user !== null ? (string) $user->display_name : 'Партнёр #'.$row->user_id,
                'manager' => $user?->personalManager?->name,
                'tokens' => (int) ($tokens[(int) $row->user_id] ?? 0),
                'agents' => $agents[(int) $row->user_id] ?? [],
                'connects' => (int) $row->connects,
                'sessions' => (int) $row->sessions,
                'tool_calls' => (int) $row->tool_calls,
                'rest_calls' => (int) $row->rest_calls,
                'errors' => (int) $row->errors,
                'orders' => (int) $row->orders,
                'questions' => (int) $row->questions,
                'first_at' => $this->stamp($row->first_at),
                'last_at' => $this->stamp($row->last_at),
            ];
        })->values()->all();
    }

    /**
     * Чем заняты агенты: разрез по операциям реестра (а для служебных
     * инструментов — по инструменту), с долей и числом партнёров.
     *
     * @param  Builder<User>  $clients
     * @return list<array<string, mixed>>
     */
    public function operations(Builder $clients, CarbonImmutable $since): array
    {
        $rows = $this->calls($clients, $since)
            ->where('kind', '<>', ClientAgentCall::KIND_MCP_CONNECT)
            ->toBase()
            ->selectRaw(implode(', ', [
                'operation',
                'tool',
                'COUNT(*) AS calls',
                'SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS errors',
                'COUNT(DISTINCT user_id) AS partners',
                "SUM(CASE WHEN kind = 'mcp_tool' THEN 1 ELSE 0 END) AS via_mcp",
            ]))
            ->groupBy('operation', 'tool')
            ->get();

        $total = (int) $rows->sum('calls');
        $grouped = [];

        foreach ($rows as $row) {
            [$key, $label, $section] = $this->taskOf($row->operation, $row->tool);

            $entry = $grouped[$key] ?? [
                'key' => $key,
                'label' => $label,
                'section' => $section,
                'calls' => 0,
                'errors' => 0,
                'partners' => 0,
                'via_mcp' => 0,
            ];

            $entry['calls'] += (int) $row->calls;
            $entry['errors'] += (int) $row->errors;
            // Партнёры по одной задаче из разных инструментов пересекаются:
            // берём максимум, а не сумму, чтобы не насчитать лишних.
            $entry['partners'] = max($entry['partners'], (int) $row->partners);
            $entry['via_mcp'] += (int) $row->via_mcp;

            $grouped[$key] = $entry;
        }

        usort($grouped, fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

        return array_map(function (array $entry) use ($total): array {
            $entry['share'] = $total > 0 ? round($entry['calls'] * 100 / $total, 1) : 0.0;

            return $entry;
        }, $grouped);
    }

    /**
     * Какими ИИ-клиентами подключаются (по clientInfo из initialize).
     *
     * @param  Builder<User>  $clients
     * @return list<array{agent: string, connects: int, tool_calls: int, partners: int}>
     */
    public function agents(Builder $clients, CarbonImmutable $since): array
    {
        return $this->calls($clients, $since)
            ->whereNotNull('agent')
            ->toBase()
            ->selectRaw(implode(', ', [
                'agent',
                "SUM(CASE WHEN kind = 'mcp_connect' THEN 1 ELSE 0 END) AS connects",
                "SUM(CASE WHEN kind = 'mcp_tool' THEN 1 ELSE 0 END) AS tool_calls",
                'COUNT(DISTINCT user_id) AS partners',
            ]))
            ->groupBy('agent')
            ->orderByDesc('tool_calls')
            ->orderByDesc('connects')
            ->get()
            ->map(fn ($row): array => [
                'agent' => (string) $row->agent,
                'connects' => (int) $row->connects,
                'tool_calls' => (int) $row->tool_calls,
                'partners' => (int) $row->partners,
            ])
            ->all();
    }

    /**
     * На чём агенты спотыкаются: коды отказов по убыванию.
     *
     * @param  Builder<User>  $clients
     * @return list<array{code: string, label: string, calls: int, partners: int}>
     */
    public function errors(Builder $clients, CarbonImmutable $since, int $limit = 10): array
    {
        return $this->calls($clients, $since)
            ->where('ok', false)
            ->whereNotNull('error_code')
            ->toBase()
            ->selectRaw('error_code, COUNT(*) AS calls, COUNT(DISTINCT user_id) AS partners')
            ->groupBy('error_code')
            ->orderByDesc('calls')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'code' => (string) $row->error_code,
                'label' => self::errorLabel((string) $row->error_code),
                'calls' => (int) $row->calls,
                'partners' => (int) $row->partners,
            ])
            ->all();
    }

    /**
     * Токены выданы, но за период не использовались: партнёр подключился и бросил
     * либо так и не подключил. Здесь и живёт разрыв между «выдали» и «пользуются».
     *
     * @param  Builder<User>  $clients
     * @return list<array<string, mixed>>
     */
    public function idleTokens(Builder $clients, CarbonImmutable $since, int $limit = 100): array
    {
        $activeUsers = ClientAgentCall::query()
            ->where('created_at', '>=', $since)
            ->select('user_id');

        return $this->activeTokens($clients)
            ->whereNotIn('user_id', $activeUsers)
            ->with(['user:id,name,erp_name,personal_manager_id', 'user.personalManager:id,name'])
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ApiToken $token): array => [
                'id' => (int) $token->id,
                'name' => $token->name,
                'partner' => [
                    'id' => (int) $token->user_id,
                    'name' => (string) ($token->user->display_name ?? 'Партнёр #'.$token->user_id),
                ],
                'manager' => $token->user->personalManager?->name,
                'created_at' => $this->stamp($token->created_at),
                'last_used_at' => $this->stamp($token->last_used_at),
            ])
            ->all();
    }

    /**
     * Карточка партнёра: вызовы по одному, свежие сверху.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function partnerCalls(User $client, CarbonImmutable $since, int $perPage = 50): LengthAwarePaginator
    {
        return ClientAgentCall::query()
            ->where('user_id', $client->getKey())
            ->where('created_at', '>=', $since)
            ->with('token:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (ClientAgentCall $call): array => $this->callRow($call));
    }

    /**
     * @return array<string, mixed>
     */
    private function callRow(ClientAgentCall $call): array
    {
        return [
            'id' => (int) $call->id,
            'kind' => $call->kind,
            'kind_label' => self::kindLabel($call->kind),
            'agent' => $call->agent,
            'token' => $call->token?->name,
            'tool' => $call->tool,
            'tool_label' => $call->tool !== null ? (self::TOOL_LABELS[$call->tool] ?? $call->tool) : null,
            'operation' => $call->operation,
            'operation_label' => $call->operation !== null ? $this->operationLabel($call->operation) : null,
            'mutating' => $call->mutating,
            'ok' => $call->ok,
            'error_code' => $call->error_code,
            'error_label' => $call->error_code !== null ? self::errorLabel($call->error_code) : null,
            'duration_ms' => (int) $call->duration_ms,
            'session_id' => $call->session_id !== null ? mb_substr($call->session_id, 0, 8) : null,
            'created_at' => $this->stamp($call->created_at),        ];
    }

    /**
     * Читаемое имя задачи: операция реестра, инструмент или вид вызова.
     */
    public function operationLabel(string $operation): string
    {
        if ($operation === 'me') {
            return 'Discovery /me';
        }

        return $this->registry->find($operation)->summary ?? $operation;
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            ClientAgentCall::KIND_MCP_CONNECT => 'Подключение агента',
            ClientAgentCall::KIND_MCP_TOOL => 'Инструмент MCP',
            ClientAgentCall::KIND_REST => 'REST v1',
            default => $kind,
        };
    }

    public static function errorLabel(string $code): string
    {
        return match ($code) {
            'validation' => 'Аргументы не приняты',
            'not_found' => 'Запись не найдена',
            'company_required' => 'Не выбрано юрлицо',
            'unknown_operation' => 'Нет такой операции',
            'operation_denied' => 'Операция закрыта агенту',
            'idempotency_key_required' => 'Нет ключа идемпотентности',
            'idempotency_key_reused' => 'Ключ идемпотентности с другим телом',
            'stock_changed' => 'Остатки изменились',
            'debt_restricted' => 'Ограничение по долгу',
            'nothing_to_place' => 'Нечего заказать',
            'unauthorized' => 'Токен не принят',
            'exception' => 'Сбой сервера',
            'rejected' => 'Отклонено бизнес-правилом',
            default => str_starts_with($code, 'http_') ? 'HTTP '.substr($code, 5) : $code,
        };
    }

    /**
     * @param  Builder<User>  $clients
     * @return Builder<ClientAgentCall>
     */
    private function calls(Builder $clients, CarbonImmutable $since): Builder
    {
        return ClientAgentCall::query()
            ->where('created_at', '>=', $since)
            ->whereIn('user_id', (clone $clients)->select('users.id'));
    }

    /**
     * @param  Builder<User>  $clients
     * @return Builder<ApiToken>
     */
    private function activeTokens(Builder $clients): Builder
    {
        return ApiToken::query()
            ->where('is_active', true)
            ->whereIn('user_id', (clone $clients)->select('users.id'));
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function agentsByUser(array $userIds, CarbonImmutable $since): array
    {
        $rows = ClientAgentCall::query()
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', $since)
            ->whereNotNull('agent')
            ->toBase()
            ->select('user_id', 'agent')
            ->distinct()
            ->get();

        $agents = [];

        foreach ($rows as $row) {
            $agents[(int) $row->user_id][] = (string) $row->agent;
        }

        return $agents;
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function taskOf(?string $operation, ?string $tool): array
    {
        if ($operation !== null) {
            $found = $this->registry->find($operation);

            return [
                'op:'.$operation,
                $found->summary ?? $this->operationLabel($operation),
                $found?->section,
            ];
        }

        if ($tool !== null) {
            return ['tool:'.$tool, self::TOOL_LABELS[$tool] ?? $tool, null];
        }

        return ['other', 'Прочее', null];
    }

    private function stamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->format('d.m.Y H:i');
    }
}

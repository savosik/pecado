<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveAgentHubLink;
use App\Models\AgentHubLink;
use App\Models\AgentTopic;
use App\Services\AgentHub\TopicModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent Hub — заведение топиков внешними агентами по ссылке-хешу.
 *
 * Ключ — тот же хеш, что открывает пульт /agent-hub/{token}: кому выдали
 * ссылку, тот и заводит топики. В ответе приходят обе агентские ссылки
 * (сайт и 1С) — дальше диалог идёт по ним через /api/agent-hub/{token}.
 *
 * @tags Agent Hub
 */
class AgentHubLinkController extends Controller
{
    public function __construct(private readonly TopicModerationService $moderation) {}

    /**
     * Точка входа: что умеет ссылка и как завести топик.
     */
    public function show(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $base = url("/api/agent-hub/links/{$link->token}");

        return response()->json([
            'link' => [
                'label' => $link->label,
                'hub_url' => $link->url(),
            ],
            'endpoints' => [
                'discovery' => "GET {$base} — этот документ",
                'topics' => "GET {$base}/topics?status=&search= — список топиков",
                'topic' => "GET {$base}/topics/{id} — топик с лентой сообщений",
                'create' => "POST {$base}/topics — JSON {\"title\": \"название\", \"task_body\": \"постановка задачи (Markdown)\", \"turn\": \"site|erp — чей первый ход, по умолчанию site\", \"agent_name\": \"кто создаёт\", \"external_key\": \"ключ идемпотентности\"}",
            ],
            'rules' => [
                'Топики не плодить: на один домен/процесс — один долгоживущий рабочий топик.',
                'Новый топик заводить самодостаточным: весь важный контекст переносить в task_body, на закрытые топики не ссылаться.',
                'Диалог агентов идёт не здесь, а по ссылкам из ответа: GET /api/agent-hub/{token}.',
                'Повторный POST с тем же external_key не создаёт дубль, а возвращает уже созданный топик.',
            ],
        ]);
    }

    /**
     * Список топиков — чтобы найти уже существующий рабочий канал, а не заводить новый.
     */
    public function index(Request $request): JsonResponse
    {
        $link = $this->link($request);

        $validated = $request->validate([
            'status' => 'nullable|in:open,in_progress,resolved,closed',
            'search' => 'nullable|string|max:255',
        ]);

        $query = AgentTopic::query()->withCount('messages');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $query->where('title', 'like', '%'.$validated['search'].'%');
        }

        $topics = $query->orderByDesc('updated_at')->limit(100)->get();

        return response()->json([
            'topics' => $topics->map(fn (AgentTopic $topic) => $this->topicPayload($topic, $link))->values(),
        ]);
    }

    /**
     * Топик с лентой сообщений — для наблюдения со стороны создателя.
     */
    public function topic(Request $request, string $token, AgentTopic $agentTopic): JsonResponse
    {
        $link = $this->link($request);

        return response()->json([
            'topic' => [
                ...$this->topicPayload($agentTopic, $link),
                'task_body' => $agentTopic->task_body,
                'resolution' => $agentTopic->resolution,
            ],
            'messages' => $agentTopic->messages()->get()->map->toApi()->values(),
        ]);
    }

    /**
     * Создать топик. Повтор с тем же external_key возвращает уже созданный.
     */
    public function store(Request $request): JsonResponse
    {
        $link = $this->link($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'task_body' => 'required|string|max:65000',
            'turn' => 'nullable|in:site,erp',
            'agent_name' => 'nullable|string|max:100',
            'external_key' => 'nullable|string|max:64',
        ], [
            'title.required' => 'Поле title обязательно: название топика.',
            'task_body.required' => 'Поле task_body обязательно: постановка задачи для агентов.',
            'turn.in' => "Допустимые значения turn: 'site' — первый ход у агента сайта, 'erp' — у агента 1С.",
        ]);

        $existing = ! empty($validated['external_key'])
            ? AgentTopic::query()
                ->where('hub_link_id', $link->id)
                ->where('external_key', $validated['external_key'])
                ->first()
            : null;

        $topic = $this->moderation->create(
            $validated['title'],
            $validated['task_body'],
            link: $link,
            agentName: $validated['agent_name'] ?? $link->label,
            externalKey: $validated['external_key'] ?? null,
        );

        if (! $existing && ! empty($validated['turn']) && $validated['turn'] !== $topic->turn) {
            $topic->forceFill(['turn' => $validated['turn'], 'turn_started_at' => now()])->save();
        }

        return response()->json([
            'topic' => [
                ...$this->topicPayload($topic->refresh(), $link),
                'task_body' => $topic->task_body,
            ],
            'repeated' => (bool) $existing,
        ], $existing ? 200 : 201);
    }

    /**
     * Ссылка-хеш текущего запроса — её положило middleware.
     *
     * Сегмент {token} объявлен и аргументом метода topic(): иначе Laravel
     * заполнил бы им параметр модели — аргументы идут в порядке маршрута.
     */
    private function link(Request $request): AgentHubLink
    {
        return $request->attributes->get(ResolveAgentHubLink::ATTRIBUTE);
    }

    /**
     * Представление топика: агентские ссылки отдаём обе — владелец хеша
     * раздаёт их сторонам сам, как это делает человек в админке.
     */
    private function topicPayload(AgentTopic $topic, AgentHubLink $link): array
    {
        return [
            'id' => $topic->id,
            'title' => $topic->title,
            'status' => $topic->status,
            'turn' => $topic->turn,
            'messages_count' => $topic->messages_count ?? $topic->messages()->count(),
            'created_by_agent' => $topic->created_by_agent,
            'external_key' => $topic->external_key,
            'updated_at' => $topic->updated_at?->toIso8601String(),
            'site_agent_url' => url("/api/agent-hub/{$topic->site_token}"),
            'erp_agent_url' => url("/api/agent-hub/{$topic->erp_token}"),
            'hub_url' => url("/agent-hub/{$link->token}/topics/{$topic->id}"),
        ];
    }
}

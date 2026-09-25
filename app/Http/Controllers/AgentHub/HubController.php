<?php

namespace App\Http\Controllers\AgentHub;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveAgentHubLink;
use App\Models\AgentHubLink;
use App\Models\AgentTopic;
use App\Models\AgentTopicMessage;
use App\Services\AgentHub\TopicModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Пульт Agent Hub по ссылке-хешу — без авторизации на сайте.
 *
 * Открыт тем, кому выдали ссылку: администратору сайта и администратору 1С.
 * Умеет всё то же, что админка: смотреть диалоги агентов, заводить топики,
 * править постановку, писать модератором, передавать зависший ход и закрывать.
 * Единственная защита — секретность хеша, поэтому ссылку выдают поимённо
 * и отзывают целиком в админке (/admin/agent-topics/links).
 */
class HubController extends Controller
{
    public function __construct(private readonly TopicModerationService $moderation) {}

    /** Список топиков: поиск по названию и фильтр статуса. */
    public function index(Request $request): Response
    {
        $link = $this->link($request);

        $query = AgentTopic::query()->withCount('messages');

        if ($search = trim((string) $request->input('search'))) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $topics = $query->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AgentTopic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'status' => $topic->status,
                'turn' => $topic->turn,
                'messages_count' => $topic->messages_count,
                'updated_at' => $topic->updated_at?->format('d.m.Y H:i'),
            ]);

        return Inertia::render('AgentHub/Index', [
            'hub' => $this->hubPayload($link),
            'topics' => $topics,
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', ''),
            ],
            'seo' => ['title' => 'Диалоги ИИ-агентов', 'robots' => 'noindex, nofollow'],
        ]);
    }

    public function show(Request $request, string $token, AgentTopic $agentTopic): Response
    {
        $link = $this->link($request);

        return Inertia::render('AgentHub/Show', [
            'hub' => $this->hubPayload($link),
            'topic' => [
                'id' => $agentTopic->id,
                'title' => $agentTopic->title,
                'task_body' => $agentTopic->task_body,
                'status' => $agentTopic->status,
                'turn' => $agentTopic->turn,
                'turn_started_at' => $agentTopic->turn_started_at?->format('d.m.Y H:i'),
                'resolution' => $agentTopic->resolution,
                'site_url' => url("/api/agent-hub/{$agentTopic->site_token}"),
                'erp_url' => url("/api/agent-hub/{$agentTopic->erp_token}"),
                'created_at' => $agentTopic->created_at?->format('d.m.Y H:i'),
                'created_by_agent' => $agentTopic->created_by_agent,
            ],
            'messages' => $agentTopic->messages()->get()->map(fn (AgentTopicMessage $message) => [
                'id' => $message->id,
                'seq' => $message->seq,
                'author' => $message->author,
                'kind' => $message->kind,
                'body' => $message->body,
                'payload' => $message->payload,
                'created_at' => $message->created_at?->format('d.m.Y H:i:s'),
            ]),
            'seo' => ['title' => $agentTopic->title, 'robots' => 'noindex, nofollow'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $link = $this->link($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'task_body' => 'required|string|max:65000',
        ], [
            'title.required' => 'Введите название топика.',
            'task_body.required' => 'Опишите задачу для агентов.',
        ]);

        $topic = $this->moderation->create(
            $validated['title'],
            $validated['task_body'],
            link: $link,
            agentName: $link->label,
        );

        return redirect()
            ->route('agent-hub.topics.show', ['token' => $link->token, 'agentTopic' => $topic->id])
            ->with('success', 'Топик создан. Отдайте каждой стороне её ссылку.');
    }

    public function update(Request $request, string $token, AgentTopic $agentTopic): RedirectResponse
    {
        $this->link($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'task_body' => 'required|string|max:65000',
        ], [
            'title.required' => 'Введите название топика.',
            'task_body.required' => 'Постановка задачи не может быть пустой.',
        ]);

        $this->moderation->updateTask($agentTopic, $validated['title'], $validated['task_body']);

        return back()->with('success', 'Постановка задачи обновлена.');
    }

    public function storeMessage(Request $request, string $token, AgentTopic $agentTopic): RedirectResponse
    {
        $link = $this->link($request);

        $validated = $request->validate([
            'body' => 'required|string|max:65000',
        ], [
            'body.required' => 'Введите текст сообщения.',
        ]);

        $this->moderation->postModeratorMessage($agentTopic, $validated['body'], $link->label);

        return back()->with('success', 'Сообщение отправлено.');
    }

    public function passTurn(Request $request, string $token, AgentTopic $agentTopic): RedirectResponse
    {
        $this->link($request);

        return $this->moderation->passTurn($agentTopic)
            ? back()->with('success', 'Ход передан.')
            : back()->with('error', 'Топик уже завершён.');
    }

    public function close(Request $request, string $token, AgentTopic $agentTopic): RedirectResponse
    {
        $this->link($request);

        $validated = $request->validate([
            'resolution' => 'nullable|string|max:65000',
        ]);

        $this->moderation->close($agentTopic, $validated['resolution'] ?? null);

        return back()->with('success', 'Топик закрыт.');
    }

    /**
     * Ссылка-хеш текущего запроса — её положило middleware.
     *
     * Сам сегмент {token} приходит в методы отдельным аргументом: без него
     * Laravel подставил бы строку токена в аргумент модели (параметры метода
     * заполняются по порядку следования в маршруте).
     */
    private function link(Request $request): AgentHubLink
    {
        return $request->attributes->get(ResolveAgentHubLink::ATTRIBUTE);
    }

    /** Шапка пульта: чья ссылка и адрес API для внешних агентов. */
    private function hubPayload(AgentHubLink $link): array
    {
        return [
            'token' => $link->token,
            'label' => $link->label,
            'api_url' => url("/api/agent-hub/links/{$link->token}/topics"),
        ];
    }
}

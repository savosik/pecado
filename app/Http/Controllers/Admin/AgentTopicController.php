<?php

namespace App\Http\Controllers\Admin;

use App\Models\AgentTopic;
use App\Models\AgentTopicMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Диалоги ИИ-агентов (агент сайта ↔ агент 1С).
 *
 * Админ создаёт топик с постановкой задачи, получает две быстрые ссылки
 * (по одной на сторону) и наблюдает диалог. Может писать как модератор,
 * передавать зависший ход и закрывать топик.
 */
class AgentTopicController extends AdminController
{
    public function index(Request $request): Response
    {
        $sortBy = in_array($request->input('sort_by'), ['id', 'title', 'status', 'updated_at'], true)
            ? $request->input('sort_by')
            : 'updated_at';
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';

        $query = AgentTopic::query()->withCount('messages');

        if ($search = trim((string) $request->input('search'))) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $topics = $query->orderBy($sortBy, $sortOrder)
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AgentTopic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'status' => $topic->status,
                'turn' => $topic->turn,
                'messages_count' => $topic->messages_count,
                'updated_at' => $topic->updated_at?->format('d.m.Y H:i'),
            ]);

        return Inertia::render('Admin/Pages/AgentTopics/Index', [
            'topics' => $topics,
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', ''),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Pages/AgentTopics/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'task_body' => 'required|string|max:65000',
        ], [
            'title.required' => 'Введите название топика.',
            'task_body.required' => 'Опишите задачу для агентов.',
        ]);

        $topic = AgentTopic::create([
            'title' => $validated['title'],
            'task_body' => $validated['task_body'],
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('admin.agent-topics.show', $topic)
            ->with('success', 'Топик создан. Отдайте каждой стороне её ссылку.');
    }

    public function show(AgentTopic $agentTopic): Response
    {
        return Inertia::render('Admin/Pages/AgentTopics/Show', [
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
        ]);
    }

    /** Сообщение модератора — вне очереди, ход не передаёт. */
    public function storeMessage(Request $request, AgentTopic $agentTopic): RedirectResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:65000',
        ], [
            'body.required' => 'Введите текст сообщения.',
        ]);

        DB::transaction(function () use ($agentTopic, $validated) {
            $topic = AgentTopic::whereKey($agentTopic->id)->lockForUpdate()->first();
            $topic->appendMessage(AgentTopicMessage::AUTHOR_MODERATOR, AgentTopicMessage::KIND_MESSAGE, $validated['body']);
        });

        return back()->with('success', 'Сообщение отправлено.');
    }

    /** Принудительная передача хода — для зависших диалогов. */
    public function passTurn(AgentTopic $agentTopic): RedirectResponse
    {
        $result = DB::transaction(function () use ($agentTopic) {
            $topic = AgentTopic::whereKey($agentTopic->id)->lockForUpdate()->first();

            if ($topic->isFinished()) {
                return false;
            }

            $topic->turn = AgentTopic::otherRole($topic->turn);
            $topic->turn_started_at = now();
            $topic->save();

            $side = $topic->turn === AgentTopic::ROLE_SITE ? 'агенту сайта' : 'агенту 1С';
            $topic->appendMessage(AgentTopicMessage::AUTHOR_SYSTEM, AgentTopicMessage::KIND_SYSTEM, "Модератор передал ход {$side}.");

            return true;
        });

        return $result
            ? back()->with('success', 'Ход передан.')
            : back()->with('error', 'Топик уже завершён.');
    }

    /** Закрытие топика администратором. */
    public function close(Request $request, AgentTopic $agentTopic): RedirectResponse
    {
        $validated = $request->validate([
            'resolution' => 'nullable|string|max:65000',
        ]);

        DB::transaction(function () use ($agentTopic, $validated) {
            $topic = AgentTopic::whereKey($agentTopic->id)->lockForUpdate()->first();

            $topic->status = AgentTopic::STATUS_CLOSED;

            if (! empty($validated['resolution'])) {
                $topic->resolution = $validated['resolution'];
            }

            $topic->save();
            $topic->appendMessage(AgentTopicMessage::AUTHOR_SYSTEM, AgentTopicMessage::KIND_SYSTEM, 'Топик закрыт модератором.');
        });

        return back()->with('success', 'Топик закрыт.');
    }
}

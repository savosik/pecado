<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Models\ChatConfirmation;
use App\Models\ChatEvent;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Assistant\AttachmentService;
use App\Services\Assistant\ThreadRefused;
use App\Services\Assistant\ThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Помощник в кабинете: страница истории и JSON-эндпоинты виджета.
 *
 * Виджет живёт на всех страницах сайта и ходит сюда: открыть тред, написать,
 * опросить состояние, подтвердить действие, загрузить файл, отметить событие
 * воронки. Всё от имени авторизованного клиента; чужой тред — 404.
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly ThreadService $threads,
        private readonly AttachmentService $attachments,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $this->client($request);

        $threads = ChatThread::query()
            ->forUser($user)
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (ChatThread $t) => $this->threads->threadToClient($t))
            ->values();

        return Inertia::render('User/Cabinet/Assistant/Index', [
            'threads' => $threads,
        ]);
    }

    public function threads(Request $request): JsonResponse
    {
        $user = $this->client($request);

        return response()->json([
            'threads' => ChatThread::query()
                ->forUser($user)
                ->orderByDesc('last_message_at')
                ->limit(30)
                ->get()
                ->map(fn (ChatThread $t) => $this->threads->threadToClient($t))
                ->values(),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $user = $this->client($request);
        $data = $request->validate(['page' => ['nullable', 'array']]);

        // Открытый тред продолжается, новый заводится по явной кнопке «Новый разговор».
        $thread = $request->boolean('fresh')
            ? null
            : ChatThread::query()->forUser($user)->open()->orderByDesc('last_message_at')->first();

        $thread ??= $this->threads->open($user, $data['page'] ?? null);

        return response()->json($this->threads->state($thread));
    }

    public function state(Request $request, ChatThread $thread): JsonResponse
    {
        $this->own($request, $thread);

        return response()->json($this->threads->state($thread, (int) $request->query('after', 0)));
    }

    public function send(Request $request, ChatThread $thread): JsonResponse
    {
        $user = $this->client($request);
        $this->own($request, $thread);

        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:8000'],
            'attachments' => ['nullable', 'array', 'max:'.(int) config('assistant.attachments.max_per_message', 5)],
            'attachments.*' => ['integer'],
            'page' => ['nullable', 'array'],
        ], [], ['text' => 'сообщение', 'attachments' => 'вложения']);

        try {
            $this->threads->post(
                $thread,
                $user,
                (string) ($data['text'] ?? ''),
                array_map('intval', $data['attachments'] ?? []),
                $data['page'] ?? null,
            );
        } catch (ThreadRefused $e) {
            return response()->json(['refused' => $e->reason, 'message' => $e->getMessage()], 409);
        }

        return response()->json($this->threads->state($thread, (int) $request->input('after', 0)));
    }

    public function close(Request $request, ChatThread $thread): JsonResponse
    {
        $this->own($request, $thread);
        $this->threads->close($thread);

        return response()->json(['thread' => $this->threads->threadToClient($thread->refresh())]);
    }

    public function decide(Request $request, ChatConfirmation $confirmation): JsonResponse
    {
        $user = $this->client($request);

        if ((int) $confirmation->user_id !== (int) $user->getKey()) {
            abort(404);
        }

        $data = $request->validate(['approve' => ['required', 'boolean']]);

        try {
            $this->threads->decide($confirmation, $user, (bool) $data['approve']);
        } catch (ThreadRefused $e) {
            return response()->json(['refused' => $e->reason, 'message' => $e->getMessage()], 409);
        }

        return response()->json($this->threads->state($confirmation->thread, (int) $request->input('after', 0)));
    }

    public function upload(Request $request, ChatThread $thread): JsonResponse
    {
        $user = $this->client($request);
        $this->own($request, $thread);

        $maxKb = (int) config('assistant.attachments.max_size_kb', 20480);

        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb],
        ], [
            'file.required' => 'Выберите файл.',
            'file.max' => 'Файл больше '.round($maxKb / 1024).' МБ.',
        ], ['file' => 'файл']);

        $file = $request->file('file');
        $mime = AttachmentService::detectMime($file);

        if (AttachmentService::kindFor($mime) === null) {
            $ext = $file->getClientOriginalExtension();

            return response()->json([
                'message' => 'Формат '.($ext !== '' ? '.'.$ext : $mime).' не поддерживается. Подойдут фото, PDF, Excel, CSV и Word.',
                'errors' => ['file' => ['Формат не поддерживается.']],
            ], 422);
        }

        $pending = ChatAttachment::query()->where('thread_id', $thread->id)->whereNull('message_id')->count();

        if ($pending >= (int) config('assistant.attachments.max_per_message', 5)) {
            return response()->json([
                'message' => 'К одному сообщению можно прикрепить не больше '.(int) config('assistant.attachments.max_per_message', 5).' файлов.',
                'errors' => ['file' => ['Слишком много файлов.']],
            ], 422);
        }

        $attachment = $this->attachments->store($thread, $user, $file);

        return response()->json(['attachment' => $attachment->toClientArray()], 201);
    }

    public function removeAttachment(Request $request, ChatAttachment $attachment): JsonResponse
    {
        $user = $this->client($request);

        if ((int) $attachment->user_id !== (int) $user->getKey() || $attachment->message_id !== null) {
            abort(404);
        }

        $attachment->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Реплика иконки для текущей страницы: одна фраза по данным или null.
     */
    public function bubble(Request $request, \App\Services\Assistant\BubbleResolver $bubbles): JsonResponse
    {
        $user = $this->client($request);

        if (! config('assistant.bubbles.enabled', true)) {
            return response()->json(['bubble' => null]);
        }

        $page = \App\Services\Assistant\PageContext::sanitize($request->only(['type', 'id', 'title', 'url']))
            ?? ['type' => 'other', 'id' => null, 'title' => null, 'url' => null];
        $shown = array_values(array_filter(explode(',', (string) $request->query('shown', ''))));

        return response()->json([
            'bubble' => $bubbles->resolve($user, $page, $request->boolean('intro'), array_slice($shown, -20)),
        ]);
    }

    public function event(Request $request): JsonResponse
    {
        $user = $this->client($request);

        $data = $request->validate([
            'event' => ['required', 'string', Rule::in(ChatEvent::CLIENT_EVENTS)],
            'page' => ['nullable', 'string', 'max:64'],
            'prompt_key' => ['nullable', 'string', 'max:64'],
            'thread_id' => ['nullable', 'integer'],
        ]);

        $thread = isset($data['thread_id'])
            ? ChatThread::query()->forUser($user)->find((int) $data['thread_id'])
            : null;

        $this->threads->event($user, $data['event'], $thread, $data['page'] ?? null, $data['prompt_key'] ?? null);

        return response()->json(['ok' => true]);
    }

    private function client(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function own(Request $request, ChatThread $thread): void
    {
        if ((int) $thread->user_id !== (int) $this->client($request)->getKey()) {
            abort(404);
        }
    }
}

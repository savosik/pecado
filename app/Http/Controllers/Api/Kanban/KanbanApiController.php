<?php

namespace App\Http\Controllers\Api\Kanban;

use App\Http\Controllers\Controller;
use App\Models\KanbanComment;
use App\Models\KanbanTask;
use App\Models\KanbanTaskAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Управление задачами канбан-доски.
 *
 * Публичный API для LLM-агентов. Активируется переменной окружения `KANBAN_API_ENABLED=true`.
 * Авторизация не требуется.
 *
 * @tags Задачи
 */
class KanbanApiController extends Controller
{
    /**
     * Список задач.
     *
     * Возвращает все задачи с комментариями и вложениями.
     * Основное использование — LLM-агент получает задачи для обработки.
     *
     * @queryParam status string Фильтр по статусу: backlog, todo, in_progress, testing, done, reopen. Example: backlog
     * @queryParam type string Фильтр по типу: bug, unexpected, ux, cosmetic, feature, improvement. Example: bug
     * @queryParam search string Поиск по заголовку и описанию. Example: форма
     * @queryParam per_page integer Записей на странице (1–100). Default: 50. Example: 20
     * @queryParam page integer Номер страницы. Default: 1. Example: 1
     */
    public function index(Request $request): JsonResponse
    {
        $query = KanbanTask::with(['comments.replies', 'attachments'])->orderBy('order');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (KanbanTask $task) => $this->format($task)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить задачу.
     *
     * Возвращает полные данные задачи: заголовок, описание, статус, тип, метаданные,
     * все комментарии (с вложенными ответами) и прикреплённые файлы.
     *
     * @urlParam id integer required ID задачи. Example: 1
     */
    public function show(int $id): JsonResponse
    {
        $task = KanbanTask::with(['comments.replies', 'attachments'])->findOrFail($id);

        return response()->json(['data' => $this->format($task)]);
    }

    /**
     * Создать задачу.
     *
     * Создаёт новую задачу в канбан-доске. По умолчанию попадает в backlog.
     *
     * @bodyParam title string required Заголовок задачи. Example: Сломана кнопка оформления заказа
     * @bodyParam description string Подробное описание. Example: При нажатии «Оформить» страница перезагружается без создания заказа
     * @bodyParam status string Статус: backlog, todo, in_progress, testing, done, reopen. Default: backlog. Example: todo
     * @bodyParam type string Тип: bug, unexpected, ux, cosmetic, feature, improvement. Default: bug. Example: bug
     * @bodyParam scope string Область: 1c, site, 1c_and_site. Example: site
     * @bodyParam page_url string URL страницы, где обнаружена проблема. Example: https://pecado.ru/checkout
     * @bodyParam browser string Браузер и разрешение. Example: Chrome · 1920×1080
     * @bodyParam user_name string Имя пользователя, сообщившего о проблеме. Example: Иванов Иван
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|string|in:backlog,todo,in_progress,testing,done,reopen',
            'type'        => 'nullable|string|in:bug,unexpected,ux,cosmetic,feature,improvement',
            'scope'       => 'nullable|string|in:1c,site,1c_and_site',
            'page_url'    => 'nullable|string|max:500',
            'browser'     => 'nullable|string|max:255',
            'user_name'   => 'nullable|string|max:255',
        ]);

        $status = $validated['status'] ?? 'backlog';
        $maxOrder = KanbanTask::where('status', $status)->max('order');

        $task = KanbanTask::create([
            ...$validated,
            'status' => $status,
            'order'  => $maxOrder !== null ? $maxOrder + 1 : 0,
        ]);

        return response()->json(['data' => $this->format($task->load(['comments.replies', 'attachments']))], 201);
    }

    /**
     * Обновить задачу.
     *
     * Можно передавать только изменённые поля. Основное использование для агентов —
     * смена статуса (`status`) после обработки задачи.
     *
     * @urlParam id integer required ID задачи. Example: 1
     * @bodyParam title string Заголовок. Example: Обновлённый заголовок
     * @bodyParam description string Описание. Example: Обновлённое описание с деталями
     * @bodyParam status string Статус: backlog, todo, in_progress, testing, done, reopen. Example: in_progress
     * @bodyParam type string Тип: bug, unexpected, ux, cosmetic, feature, improvement. Example: feature
     * @bodyParam scope string Область: 1c, site, 1c_and_site. Example: site
     * @bodyParam page_url string URL страницы. Example: https://pecado.ru/checkout
     * @bodyParam browser string Браузер и разрешение. Example: Chrome · 1920×1080
     * @bodyParam user_name string Имя пользователя. Example: Иванов Иван
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $task = KanbanTask::findOrFail($id);

        $validated = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'sometimes|string|in:backlog,todo,in_progress,testing,done,reopen',
            'type'        => 'nullable|string|in:bug,unexpected,ux,cosmetic,feature,improvement',
            'scope'       => 'nullable|string|in:1c,site,1c_and_site',
            'page_url'    => 'nullable|string|max:500',
            'browser'     => 'nullable|string|max:255',
            'user_name'   => 'nullable|string|max:255',
        ]);

        $task->update($validated);

        return response()->json(['data' => $this->format($task->fresh(['comments.replies', 'attachments']))]);
    }

    /**
     * Удалить задачу.
     *
     * Удаление разрешено только для задач в статусе `backlog` или `done`.
     * Удаляются все связанные комментарии и вложения (файлы с диска).
     *
     * @urlParam id integer required ID задачи. Example: 1
     */
    public function destroy(int $id): JsonResponse
    {
        $task = KanbanTask::findOrFail($id);

        if (! in_array($task->status, ['backlog', 'done'])) {
            return response()->json([
                'error' => 'Удалять можно только задачи в статусе backlog или done.',
                'current_status' => $task->status,
            ], 422);
        }

        foreach ($task->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->path);
        }

        $task->delete();

        return response()->json(null, 204);
    }

    /**
     * Добавить комментарий к задаче.
     *
     * Поддерживает вложенные ответы через `parent_id`.
     * Агент может оставлять комментарии с описанием хода работы или результата.
     *
     * @urlParam id integer required ID задачи. Example: 1
     * @bodyParam content string required Текст комментария. Example: Исследовал проблему — баг в компоненте Checkout.jsx на строке 42
     * @bodyParam parent_id integer ID родительского комментария для ответа. Example: 5
     */
    public function addComment(Request $request, int $id): JsonResponse
    {
        $task = KanbanTask::findOrFail($id);

        $validated = $request->validate([
            'content'   => 'required|string',
            'parent_id' => 'nullable|integer|exists:kanban_comments,id',
        ]);

        $comment = KanbanComment::create([
            'kanban_task_id' => $task->id,
            'content'        => $validated['content'],
            'parent_id'      => $validated['parent_id'] ?? null,
        ]);

        return response()->json(['data' => $this->formatComment($comment->load('replies'))], 201);
    }

    /**
     * Удалить комментарий.
     *
     * @urlParam commentId integer required ID комментария. Example: 3
     */
    public function deleteComment(int $commentId): JsonResponse
    {
        $comment = KanbanComment::findOrFail($commentId);
        $comment->delete();

        return response()->json(null, 204);
    }

    /**
     * Прикрепить файл к задаче.
     *
     * @urlParam id integer required ID задачи. Example: 1
     * @bodyParam file file required Файл для прикрепления (макс. 20 МБ).
     */
    public function addAttachment(Request $request, int $id): JsonResponse
    {
        $task = KanbanTask::findOrFail($id);

        $request->validate([
            'file' => 'required|file|max:20480',
        ]);

        $file = $request->file('file');
        $path = $file->store("kanban-attachments/{$task->id}", 'public');

        $attachment = KanbanTaskAttachment::create([
            'kanban_task_id' => $task->id,
            'original_name'  => $file->getClientOriginalName(),
            'path'           => $path,
            'mime_type'      => $file->getMimeType(),
            'size'           => $file->getSize(),
        ]);

        return response()->json(['data' => $this->formatAttachment($attachment)], 201);
    }

    /**
     * Удалить вложение.
     *
     * @urlParam attachmentId integer required ID вложения. Example: 7
     */
    public function deleteAttachment(int $attachmentId): JsonResponse
    {
        $attachment = KanbanTaskAttachment::findOrFail($attachmentId);
        Storage::disk('public')->delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    private function format(KanbanTask $task): array
    {
        return [
            'id'          => $task->id,
            'title'       => $task->title,
            'description' => $task->description,
            'status'      => $task->status,
            'type'        => $task->type,
            'scope'       => $task->scope,
            'order'       => $task->order,
            'page_url'    => $task->page_url,
            'browser'     => $task->browser,
            'user_name'   => $task->user_name,
            'comments'    => $task->comments->map(fn ($c) => $this->formatComment($c))->values()->toArray(),
            'attachments' => $task->attachments->map(fn ($a) => $this->formatAttachment($a))->values()->toArray(),
            'created_at'  => $task->created_at?->toIso8601String(),
            'updated_at'  => $task->updated_at?->toIso8601String(),
        ];
    }

    private function formatComment(KanbanComment $comment): array
    {
        return [
            'id'         => $comment->id,
            'content'    => $comment->content,
            'parent_id'  => $comment->parent_id,
            'replies'    => $comment->replies->map(fn ($r) => $this->formatComment($r))->values()->toArray(),
            'created_at' => $comment->created_at?->toIso8601String(),
            'updated_at' => $comment->updated_at?->toIso8601String(),
        ];
    }

    private function formatAttachment(KanbanTaskAttachment $attachment): array
    {
        return [
            'id'            => $attachment->id,
            'original_name' => $attachment->original_name,
            'url'           => $attachment->url,
            'mime_type'     => $attachment->mime_type,
            'size'          => $attachment->size,
            'created_at'    => $attachment->created_at?->toIso8601String(),
        ];
    }
}

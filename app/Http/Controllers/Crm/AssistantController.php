<?php

namespace App\Http\Controllers\Crm;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ClientAssistantNote;
use App\Services\Assistant\ThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Вкладка «Помощник» в карточке партнёра: переписка клиента с агентом, только
 * чтение (решение заказчика 19.09.2026). Право то же, что у раздела
 * «ИИ-агенты клиентов» — это один домен; чужой партнёр — 404, как везде в CRM.
 */
class AssistantController extends CrmController
{
    public function index(Request $request, int $client): JsonResponse
    {
        abort_unless($this->crmActor($request)->can('crm-agent-usage.view'), 403);
        $partner = $this->partner($request, $client);

        $threads = ChatThread::query()
            ->forUser($partner)
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->withCount('messages')
            ->get()
            ->map(fn (ChatThread $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'summary' => $t->summary,
                'messages_count' => (int) $t->messages_count,
                'cost' => (float) $t->cost,
                'page' => $t->page,
                'last_message_at' => $t->last_message_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->values();

        $note = ClientAssistantNote::query()->where('user_id', $partner->getKey())->first();

        return response()->json([
            'threads' => $threads,
            'note' => $note ? [
                'content' => $note->content,
                'version' => $note->version,
                'updated_by' => $note->updated_by,
                'updated_at' => $note->updated_at?->toIso8601String(),
            ] : null,
            'totals' => [
                'threads' => $threads->count(),
                'cost' => round((float) $threads->sum('cost'), 4),
            ],
        ]);
    }

    public function thread(Request $request, int $client, ChatThread $thread): JsonResponse
    {
        abort_unless($this->crmActor($request)->can('crm-agent-usage.view'), 403);
        $partner = $this->partner($request, $client);

        if ((int) $thread->user_id !== (int) $partner->getKey()) {
            abort(404);
        }

        $messages = $thread->messages()
            ->with('attachments')
            ->get()
            ->map(function (ChatMessage $m) {
                $row = ThreadService::messageToClient($m);
                $row['cost'] = (float) $m->cost;
                $row['model'] = $m->model;
                $row['attachments'] = $m->attachments->map(fn (ChatAttachment $a) => $a->toClientArray() + [
                    'download_url' => route('crm.assistant.attachments.download', $a),
                ])->values()->all();

                return $row;
            })
            ->values();

        return response()->json([
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'status' => $thread->status,
                'summary' => $thread->summary,
                'cost' => (float) $thread->cost,
                'tokens' => $thread->totalTokens(),
                'page' => $thread->page,
                'created_at' => $thread->created_at?->toIso8601String(),
                'closed_at' => $thread->closed_at?->toIso8601String(),
            ],
            'messages' => $messages,
            'confirmations' => $thread->confirmations()->orderBy('id')->get()->map(fn ($c) => $c->toClientArray())->values(),
        ]);
    }

    public function download(Request $request, ChatAttachment $attachment): StreamedResponse
    {
        abort_unless($this->crmActor($request)->can('crm-agent-usage.view'), 403);
        $this->partner($request, (int) $attachment->user_id);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    /**
     * Партнёр в охвате сотрудника: чужой — 404, не 403 (как везде в CRM).
     */
    private function partner(Request $request, int $client): \App\Models\User
    {
        return \App\Models\User::query()
            ->openableInCrm($this->crmActor($request))
            ->findOrFail($client);
    }
}

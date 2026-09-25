<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\User;
use App\Models\UserQuestion;
use App\Services\Support\UserQuestionCrmQuery;
use App\Services\Support\UserQuestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CRM-раздел «Вопросы клиентов»: что спрашивают партнёры и что им ответили.
 *
 * Вопросы приходят с формы на сайте, из кабинета и через клиентский API
 * (`questions.create`), а отвечает на них персональный менеджер — поэтому
 * раздел живёт в CRM, а не только в админке. Данные те же (`user_questions`),
 * ответ и отклонение — через {@see UserQuestionService}, как в админке.
 */
class QuestionController extends CrmController
{
    public function __construct(
        private readonly UserQuestionCrmQuery $questions,
        private readonly UserQuestionService $service,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $actor = $this->crmActor($request);
        $scope = CrmScope::fromRequest($request, $actor);
        $status = $this->questions->statusFilter($request->input('status'));
        $search = trim((string) $request->input('search'));

        $visible = $this->questions->visible($actor, $scope);

        $query = (clone $visible)->with([
            'user:id,name,erp_name,personal_manager_id',
            'user.personalManager:id,name',
            'answeredBy:id,name',
        ]);
        $this->questions->applyStatus($query, $status);

        if ($search !== '') {
            $this->questions->applySearch($query, $search);
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (UserQuestion $q) => $this->row($q));

        return Inertia::render('Crm/Pages/Questions/Index', [
            'questions' => $rows,
            'counts' => $this->questions->counts($visible),
            'filters' => [
                'status' => $status,
                'search' => $search,
                'scope' => $scope->value,
            ],
            'canSeeDepartment' => $this->seesDepartment($request),
            'canEdit' => $actor->can('crm-questions.edit'),
        ]);
    }

    public function show(Request $request, int $question): InertiaResponse
    {
        $actor = $this->crmActor($request);
        $model = $this->find($actor, $question);

        // Открыл — значит взял в работу: клиент в кабинете видит «В работе»
        // вместо «Новый». Тот, кто отвечать не может, статус не трогает.
        if ($actor->can('crm-questions.edit')) {
            $model->markInProgress($actor);
            $model->refresh();
        }

        $model->load([
            'user:id,name,erp_name,personal_manager_id',
            'user.personalManager:id,name',
            'answeredBy:id,name',
        ]);

        $attachment = $model->getFirstMedia('attachment');

        return Inertia::render('Crm/Pages/Questions/Show', [
            'question' => [
                ...$this->row($model),
                'body' => $model->body,
                'answer' => $model->answer,
                'rejected_reason' => $model->rejected_reason,
                'attachment' => $attachment ? [
                    'name' => $attachment->name,
                    'size' => $attachment->size,
                    'url' => route('crm.questions.attachment', $model),
                ] : null,
            ],
            'canEdit' => $actor->can('crm-questions.edit'),
        ]);
    }

    public function answer(Request $request, int $question): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $model = $this->find($actor, $question);

        $data = $request->validate([
            'answer' => ['required', 'string', 'min:2', 'max:5000'],
        ], [
            'answer.required' => 'Напишите ответ клиенту.',
            'answer.min' => 'Ответ слишком короткий.',
            'answer.max' => 'Ответ не длиннее 5000 символов.',
        ]);

        $this->service->answer($model, $actor, $data['answer']);

        return redirect()
            ->route('crm.questions.show', $model)
            ->with('success', 'Ответ отправлен клиенту.');
    }

    public function reject(Request $request, int $question): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $model = $this->find($actor, $question);

        $data = $request->validate([
            'rejected_reason' => ['nullable', 'string', 'max:500'],
        ], [
            'rejected_reason.max' => 'Причина не длиннее 500 символов.',
        ]);

        $this->service->reject($model, $actor, $data['rejected_reason'] ?? null);

        return redirect()
            ->route('crm.questions.show', $model)
            ->with('success', 'Вопрос отклонён. Клиент письма не получит.');
    }

    public function downloadAttachment(Request $request, int $question): BinaryFileResponse
    {
        $model = $this->find($this->crmActor($request), $question);
        $media = $model->getFirstMedia('attachment');
        abort_unless($media !== null, 404);

        return response()->download($media->getPath(), $media->name);
    }

    /**
     * Чужой вопрос — 404, а не 403: сама попытка не должна подтверждать, что он есть.
     */
    private function find(User $actor, int $id): UserQuestion
    {
        return $this->questions->accessible($actor)->whereKey($id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(UserQuestion $q): array
    {
        $client = $q->user;

        return [
            'id' => $q->id,
            'subject' => $q->subject,
            'body_preview' => mb_substr($q->body, 0, 160),
            'status' => $q->status->value,
            'status_label' => $q->status->label(),
            'status_color' => $q->status->color(),
            'name' => $q->name,
            'email' => $q->email,
            'is_registered' => $client !== null,
            'client' => $client !== null ? [
                'id' => $client->id,
                'name' => (string) $client->display_name,
                'url' => route('crm.clients.show', $client),
            ] : null,
            'manager' => $client?->personalManager?->name,
            'has_attachment' => $q->getFirstMedia('attachment') !== null,
            'created_at' => $q->created_at?->format('d.m.Y H:i'),
            'answered_at' => $q->answered_at?->format('d.m.Y H:i'),
            'answered_by' => $q->answeredBy?->name,
        ];
    }
}

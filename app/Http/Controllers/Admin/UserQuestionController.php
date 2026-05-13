<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserQuestionStatus;
use App\Http\Requests\Admin\AnswerUserQuestionRequest;
use App\Http\Requests\Admin\RejectUserQuestionRequest;
use App\Models\UserQuestion;
use App\Notifications\UserQuestions\QuestionAnsweredNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserQuestionController extends AdminController
{
    public function index(Request $request): Response
    {
        $query = UserQuestion::query()->with(['user:id,name,email', 'answeredBy:id,name']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $questions = $query->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (UserQuestion $q) => [
                'id' => $q->id,
                'email' => $q->email,
                'name' => $q->name,
                'subject' => $q->subject,
                'body_preview' => mb_substr($q->body, 0, 120),
                'status' => $q->status->value,
                'status_label' => $q->status->label(),
                'status_color' => $q->status->color(),
                'has_attachment' => $q->getFirstMedia('attachment') !== null,
                'is_registered' => $q->user_id !== null,
                'created_at' => $q->created_at?->toIso8601String(),
                'answered_at' => $q->answered_at?->toIso8601String(),
            ]);

        $statuses = collect(UserQuestionStatus::cases())->map(fn ($s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'color' => $s->color(),
        ])->values();

        return Inertia::render('Admin/Pages/UserQuestions/Index', [
            'questions' => $questions,
            'filters' => [
                'status' => $request->input('status'),
                'search' => $request->input('search'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ],
            'statuses' => $statuses,
        ]);
    }

    public function show(UserQuestion $question): Response
    {
        $manager = Auth::user();
        if ($manager) {
            $question->markInProgress($manager);
            $question->refresh();
        }

        $question->load(['user:id,name,email', 'answeredBy:id,name']);
        $attachment = $question->getFirstMedia('attachment');

        return Inertia::render('Admin/Pages/UserQuestions/Show', [
            'question' => [
                'id' => $question->id,
                'email' => $question->email,
                'name' => $question->name,
                'subject' => $question->subject,
                'body' => $question->body,
                'answer' => $question->answer,
                'rejected_reason' => $question->rejected_reason,
                'status' => $question->status->value,
                'status_label' => $question->status->label(),
                'status_color' => $question->status->color(),
                'created_at' => $question->created_at?->toIso8601String(),
                'answered_at' => $question->answered_at?->toIso8601String(),
                'ip' => $question->ip,
                'user_agent' => $question->user_agent,
                'is_registered' => $question->user_id !== null,
                'user' => $question->user ? [
                    'id' => $question->user->id,
                    'name' => $question->user->name,
                    'email' => $question->user->email,
                ] : null,
                'answered_by' => $question->answeredBy ? [
                    'id' => $question->answeredBy->id,
                    'name' => $question->answeredBy->name,
                ] : null,
                'attachment' => $attachment ? [
                    'name' => $attachment->name,
                    'size' => $attachment->size,
                    'mime_type' => $attachment->mime_type,
                    'url' => route('admin.user-questions.attachment', $question),
                ] : null,
            ],
            'statuses' => collect(UserQuestionStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ])->values(),
        ]);
    }

    public function answer(AnswerUserQuestionRequest $request, UserQuestion $question): RedirectResponse
    {
        $manager = $request->user();
        $question->markAnswered($manager, $request->string('answer'));

        if ($question->user_id !== null && $question->user) {
            $question->user->notify(new QuestionAnsweredNotification($question));
        } else {
            Notification::route('mail', $question->email)
                ->notify(new QuestionAnsweredNotification($question));
        }

        return redirect()
            ->route('admin.user-questions.show', $question)
            ->with('success', 'Ответ отправлен пользователю.');
    }

    public function reject(RejectUserQuestionRequest $request, UserQuestion $question): RedirectResponse
    {
        $question->markRejected($request->user(), $request->input('rejected_reason'));

        return redirect()
            ->route('admin.user-questions.show', $question)
            ->with('success', 'Вопрос помечен как отклонённый. Пользователь уведомление не получит.');
    }

    public function updateStatus(Request $request, UserQuestion $question): RedirectResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:new,in_progress,answered,rejected'],
        ]);

        $question->update(['status' => $request->input('status')]);

        return redirect()
            ->route('admin.user-questions.show', $question)
            ->with('success', 'Статус обновлён.');
    }

    public function destroy(UserQuestion $question): RedirectResponse
    {
        $question->delete();

        return redirect()
            ->route('admin.user-questions.index')
            ->with('success', 'Вопрос удалён.');
    }

    public function downloadAttachment(UserQuestion $question): BinaryFileResponse
    {
        $media = $question->getFirstMedia('attachment');
        abort_unless($media !== null, 404);

        return response()->download($media->getPath(), $media->name);
    }
}

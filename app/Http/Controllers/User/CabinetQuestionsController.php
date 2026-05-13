<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\UserQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CabinetQuestionsController extends Controller
{
    public function index(): InertiaResponse
    {
        $user = Auth::user();

        $questions = UserQuestion::forUser($user)
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (UserQuestion $q) => [
                'id' => $q->id,
                'subject' => $q->subject,
                'status' => $q->status->value,
                'status_label' => $q->status->label(),
                'status_color' => $q->status->color(),
                'has_answer' => $q->answer !== null,
                'has_attachment' => $q->getFirstMedia('attachment') !== null,
                'created_at' => $q->created_at?->toIso8601String(),
                'answered_at' => $q->answered_at?->toIso8601String(),
            ]);

        return Inertia::render('User/Cabinet/Questions/Index', [
            'questions' => $questions,
        ]);
    }

    public function show(UserQuestion $question): InertiaResponse
    {
        $this->ensureOwner($question);

        $attachment = $question->getFirstMedia('attachment');

        return Inertia::render('User/Cabinet/Questions/Show', [
            'question' => [
                'id' => $question->id,
                'subject' => $question->subject,
                'body' => $question->body,
                'answer' => $question->answer,
                'status' => $question->status->value,
                'status_label' => $question->status->label(),
                'status_color' => $question->status->color(),
                'created_at' => $question->created_at?->toIso8601String(),
                'answered_at' => $question->answered_at?->toIso8601String(),
                'attachment' => $attachment ? [
                    'name' => $attachment->name,
                    'size' => $attachment->size,
                    'url' => route('cabinet.questions.attachment', $question),
                ] : null,
            ],
        ]);
    }

    public function downloadAttachment(UserQuestion $question): BinaryFileResponse|StreamedResponse|RedirectResponse|Response
    {
        $this->ensureOwner($question);

        $media = $question->getFirstMedia('attachment');
        abort_unless($media !== null, 404);

        return response()->download($media->getPath(), $media->name);
    }

    private function ensureOwner(UserQuestion $question): void
    {
        abort_unless($question->user_id === Auth::id(), 404);
    }
}

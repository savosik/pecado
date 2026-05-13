<?php

namespace App\Http\Controllers\User;

use App\Enums\UserQuestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserQuestionRequest;
use App\Models\User;
use App\Models\UserQuestion;
use App\Notifications\UserQuestions\NewQuestionAdminNotification;
use App\Notifications\UserQuestions\QuestionReceivedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class UserQuestionController extends Controller
{
    public function store(StoreUserQuestionRequest $request): RedirectResponse
    {
        $user = Auth::user();

        $email = $user?->email ?? (string) $request->input('email');
        $name = $user?->name ?? $request->input('name');

        $question = UserQuestion::create([
            'user_id' => $user?->id,
            'name' => $name,
            'email' => $email,
            'subject' => $request->string('subject'),
            'body' => $request->string('body'),
            'status' => UserQuestionStatus::NEW,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        if ($request->hasFile('file')) {
            $question->addMediaFromRequest('file')->toMediaCollection('attachment');
        }

        if ($user) {
            $user->notify(new QuestionReceivedNotification($question));
        } else {
            Notification::route('mail', $email)
                ->notify(new QuestionReceivedNotification($question));
        }

        $managers = User::role(['content-manager', 'super-admin'])->get();
        if ($managers->isNotEmpty()) {
            Notification::send($managers, new NewQuestionAdminNotification($question));
        }

        return back()
            ->with('success', 'Ваш вопрос отправлен. Мы ответим в течение 1 рабочего дня.');
    }
}

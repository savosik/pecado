<?php

namespace App\Http\Controllers\User;

use App\Enums\UserQuestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserQuestionRequest;
use App\Models\UserQuestion;
use App\Notifications\UserQuestions\NewQuestionAdminNotification;
use App\Notifications\UserQuestions\QuestionReceivedNotification;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
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

        // Сигнал пульту идёт всегда: в теневом режиме он только считает
        // получателей для сверки со старой адресацией.
        app(MailStream::class)->captureQuietly(new Occasion(
            key: 'system.question_received',
            clientUserId: $user?->id,
            subject: $question,
            data: [
                'is_guest' => $user === null,
                'question_id' => $question->id,
            ],
            view: [
                'title' => 'Новый вопрос с сайта',
                'body' => (string) $question->question,
                'entity_label' => 'Вопрос №'.$question->id,
            ],
        ));

        // Адресаты заданы явным списком, а не выборкой по ролям: роль раздаёт
        // права, а не почту, и любая новая роль у сотрудника молча подписывала бы
        // его на переписку с клиентами. Пустой список — письма не уходят, вопрос
        // всё равно виден в админке.
        foreach (config('notifications.mail.user_question_recipients', []) as $recipient) {
            Notification::route('mail', $recipient)
                ->notify(new NewQuestionAdminNotification($question));
        }

        return back()
            ->with('success', 'Ваш вопрос отправлен. Мы ответим в течение 1 рабочего дня.');
    }
}

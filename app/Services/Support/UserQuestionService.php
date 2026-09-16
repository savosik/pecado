<?php

namespace App\Services\Support;

use App\Enums\UserQuestionStatus;
use App\Models\User;
use App\Models\UserQuestion;
use App\Notifications\UserQuestions\NewQuestionAdminNotification;
use App\Notifications\UserQuestions\QuestionReceivedNotification;
use App\Services\Crm\Mail\MailStream;
use App\Services\Notifications\StaffNotifications;
use App\Support\Notifications\Occasion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

/**
 * Вопрос клиента менеджеру — одна реализация для формы на сайте и API v1.
 *
 * Адресаты сотрудников заданы явным списком, а не выборкой по ролям: роль
 * раздаёт права, а не почту. Пустой список — письма не уходят, вопрос всё
 * равно виден в админке.
 */
class UserQuestionService
{
    public function __construct(
        private readonly MailStream $mailStream,
        private readonly StaffNotifications $staff,
    ) {}

    /**
     * @param  array{subject: string, body: string, email?: ?string, name?: ?string}  $data
     * @param  array{ip?: ?string, user_agent?: ?string, source?: ?string}  $meta
     */
    public function create(?User $user, array $data, array $meta = [], ?UploadedFile $file = null): UserQuestion
    {
        $email = $user?->email ?? (string) ($data['email'] ?? '');
        $name = $user?->name ?? ($data['name'] ?? null);

        $question = UserQuestion::create([
            'user_id' => $user?->id,
            'name' => $name,
            'email' => $email,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'status' => UserQuestionStatus::NEW,
            'ip' => $meta['ip'] ?? null,
            'user_agent' => substr((string) ($meta['user_agent'] ?? ''), 0, 500),
        ]);

        if ($file !== null) {
            $question->addMedia($file)->toMediaCollection('attachment');
        }

        if ($user) {
            $user->notify(new QuestionReceivedNotification($question));
        } elseif ($email !== '') {
            Notification::route('mail', $email)->notify(new QuestionReceivedNotification($question));
        }

        // Сигнал пульту идёт всегда: в теневом режиме он только считает
        // получателей для сверки со старой адресацией.
        $this->mailStream->captureQuietly(new Occasion(
            key: 'system.question_received',
            clientUserId: $user?->id,
            subject: $question,
            data: [
                'is_guest' => $user === null,
                'question_id' => $question->id,
                'source' => $meta['source'] ?? 'web',
            ],
            view: [
                'title' => 'Новый вопрос с сайта',
                'body' => (string) $question->question,
                'entity_label' => 'Вопрос №'.$question->id,
            ],
        ));

        foreach (config('notifications.mail.user_question_recipients', []) as $recipient) {
            // Сотрудник может отписаться у себя в «Моих уведомлениях».
            // Общий ящик отдела учётки не имеет — ему письмо уходит всегда.
            if (! $this->staff->wantsByEmail($recipient, 'staff.question_received')) {
                continue;
            }

            Notification::route('mail', $recipient)->notify(new NewQuestionAdminNotification($question));
        }

        return $question;
    }

    /**
     * Представление вопроса для клиента.
     *
     * @return array<string, mixed>
     */
    public function payload(UserQuestion $question, bool $withBody = true): array
    {
        $attachment = $question->getFirstMedia('attachment');

        return [
            'id' => $question->id,
            'subject' => $question->subject,
            'body' => $withBody ? $question->body : null,
            'answer' => $withBody ? $question->answer : null,
            'status' => $question->status->value,
            'status_label' => $question->status->label(),
            'has_answer' => $question->answer !== null,
            'has_attachment' => $attachment !== null,
            'attachment_name' => $attachment?->name,
            'created_at' => $question->created_at?->toIso8601String(),
            'answered_at' => $question->answered_at?->toIso8601String(),
        ];
    }
}

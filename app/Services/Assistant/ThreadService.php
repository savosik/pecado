<?php

namespace App\Services\Assistant;

use App\Jobs\Assistant\RunAssistantTurn;
use App\Models\ChatAttachment;
use App\Models\ChatConfirmation;
use App\Models\ChatEvent;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Треды чата-помощника: открыть, написать, подтвердить, закрыть, опросить.
 *
 * Здесь же — правила, которые не зависят от транспорта: один ход модели на
 * тред одновременно, квоты до отправки, история append-only, служебные ходы
 * подтверждений. Контроллер кабинета и CRM только вызывают эти методы.
 */
final class ThreadService
{
    /** Очередь ходов помощника: свой супервизор Horizon с таймаутом под долгий ответ модели. */
    public const QUEUE = 'assistant';

    public function __construct(
        private readonly AssistantAvailability $availability,
        private readonly AssistantQuotas $quotas,
        private readonly AssistantTokenIssuer $tokens,
    ) {}

    /**
     * @param  array<string, mixed>|null  $page
     */
    public function open(User $user, ?array $page = null): ChatThread
    {
        return ChatThread::create([
            'user_id' => $user->getKey(),
            'status' => ChatThread::STATUS_OPEN,
            'page' => PageContext::sanitize($page),
            'last_message_at' => now(),
        ]);
    }

    /**
     * Реплика клиента → ход в очереди. Возвращает заглушку ответа модели.
     *
     * @param  list<int>  $attachmentIds
     * @param  array<string, mixed>|null  $page
     *
     * @throws ThreadRefused
     */
    public function post(ChatThread $thread, User $user, string $text, array $attachmentIds = [], ?array $page = null): ChatMessage
    {
        $text = trim($text);

        if (! $thread->isOpen()) {
            throw new ThreadRefused('closed', 'Разговор закрыт. Начните новый.');
        }

        if ($this->isBusy($thread)) {
            throw new ThreadRefused('busy', 'Помощник ещё отвечает, подождите.');
        }

        if (! $this->availability->isAvailable()) {
            throw new ThreadRefused('unavailable', 'Помощник сейчас недоступен, попробуйте позже.');
        }

        if ($quota = $this->quotas->check($thread)) {
            throw new ThreadRefused($quota['code'], $quota['message']);
        }

        $attachments = $attachmentIds === [] ? collect() : ChatAttachment::query()
            ->where('thread_id', $thread->id)
            ->whereNull('message_id')
            ->whereIn('id', $attachmentIds)
            ->get();

        if ($text === '' && $attachments->isEmpty()) {
            throw new ThreadRefused('empty', 'Напишите вопрос или прикрепите файл.');
        }

        $pageContext = PageContext::sanitize($page) ?? $thread->page;
        $isFirst = ! $thread->messages()->where('role', ChatMessage::ROLE_USER)->exists();

        $placeholder = DB::transaction(function () use ($thread, $user, $text, $attachments, $pageContext, $isFirst): ChatMessage {
            $content = [];

            foreach ($attachments as $attachment) {
                $content[] = AttachmentService::placeholder($attachment);
            }

            $body = $text;
            $described = PageContext::describe($pageContext);

            if ($described !== null) {
                $body = ($body !== '' ? $body."\n\n" : '').$described;
            }

            if ($body === '') {
                $body = 'Посмотрите, пожалуйста, вложение.';
            }

            $content[] = ['type' => 'text', 'text' => $body];

            $userMessage = ChatMessage::create([
                'thread_id' => $thread->id,
                'role' => ChatMessage::ROLE_USER,
                'kind' => ChatMessage::KIND_MESSAGE,
                'content' => $content,
                'text' => $text !== '' ? $text : '(вложение)',
                'status' => ChatMessage::STATUS_DONE,
            ]);

            if ($attachments->isNotEmpty()) {
                ChatAttachment::query()->whereIn('id', $attachments->pluck('id'))->update(['message_id' => $userMessage->id]);
            }

            $placeholder = ChatMessage::create([
                'thread_id' => $thread->id,
                'role' => ChatMessage::ROLE_ASSISTANT,
                'kind' => ChatMessage::KIND_MESSAGE,
                'content' => [],
                'text' => '',
                'status' => ChatMessage::STATUS_PENDING,
            ]);

            $thread->forceFill([
                'title' => $thread->title ?? Str::limit($text !== '' ? $text : 'Вложение', 80, '…'),
                'page' => $thread->page ?? $pageContext,
                'last_message_at' => now(),
            ])->save();

            if ($isFirst) {
                $this->event($user, ChatEvent::FIRST_MESSAGE, $thread, $pageContext['type'] ?? null);
            }

            return $placeholder;
        });

        // После коммита: воркер очереди не должен взять ход раньше, чем он записан.
        RunAssistantTurn::dispatch($thread->id, $placeholder->id)->onQueue(self::QUEUE);

        return $placeholder;
    }

    /**
     * Клиент нажал «Подтвердить» или «Отмена» на карточке. Служебный ход
     * уходит в тред как реплика клиента (история append-only), модель
     * повторяет или не повторяет вызов операции.
     *
     * @throws ThreadRefused
     */
    public function decide(ChatConfirmation $confirmation, User $user, bool $approve): ChatMessage
    {
        if (! $confirmation->isPending()) {
            throw new ThreadRefused('confirmation_stale', 'Карточка уже не действует: подтвердите действие заново в чате.');
        }

        /** @var ChatThread $thread */
        $thread = $confirmation->thread;

        if ($this->isBusy($thread)) {
            throw new ThreadRefused('busy', 'Помощник ещё отвечает, подождите.');
        }

        $confirmation->forceFill([
            'status' => $approve ? ChatConfirmation::STATUS_APPROVED : ChatConfirmation::STATUS_DECLINED,
            'decided_at' => now(),
        ])->save();

        $label = (string) ($confirmation->summary['label'] ?? $confirmation->operation);

        $text = $approve
            ? "Клиент подтвердил действие #{$confirmation->id} («{$label}»). Выполните операцию {$confirmation->operation} ещё раз с теми же аргументами."
            : "Клиент отказался от действия #{$confirmation->id} («{$label}»). Не повторяйте вызов; спросите, что изменить.";

        $placeholder = DB::transaction(function () use ($thread, $user, $approve, $text): ChatMessage {
            ChatMessage::create([
                'thread_id' => $thread->id,
                'role' => ChatMessage::ROLE_USER,
                'kind' => ChatMessage::KIND_CONFIRMATION,
                'content' => [['type' => 'text', 'text' => $text]],
                'text' => $approve ? 'Подтверждено' : 'Отменено',
                'status' => ChatMessage::STATUS_DONE,
            ]);

            $placeholder = ChatMessage::create([
                'thread_id' => $thread->id,
                'role' => ChatMessage::ROLE_ASSISTANT,
                'kind' => ChatMessage::KIND_MESSAGE,
                'content' => [],
                'text' => '',
                'status' => ChatMessage::STATUS_PENDING,
            ]);

            $thread->forceFill(['last_message_at' => now()])->save();

            if ($approve) {
                $this->event($user, ChatEvent::CONFIRMED_ACTION, $thread, null);
            }

            return $placeholder;
        });

        RunAssistantTurn::dispatch($thread->id, $placeholder->id)->onQueue(self::QUEUE);

        return $placeholder;
    }

    public function close(ChatThread $thread): void
    {
        if (! $thread->isOpen()) {
            return;
        }

        $thread->forceFill(['status' => ChatThread::STATUS_CLOSED, 'closed_at' => now()])->save();
        $this->tokens->revokeForThread($thread);

        ChatConfirmation::query()
            ->where('thread_id', $thread->id)
            ->whereIn('status', [ChatConfirmation::STATUS_PENDING, ChatConfirmation::STATUS_APPROVED])
            ->update(['status' => ChatConfirmation::STATUS_EXPIRED]);
    }

    public function isBusy(ChatThread $thread): bool
    {
        return $thread->messages()
            ->whereIn('status', [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_STREAMING])
            ->exists();
    }

    /**
     * Состояние треда для опроса: ходы после `after` плюс незавершённый ход,
     * ожидающие подтверждения, занят ли помощник.
     *
     * @return array<string, mixed>
     */
    public function state(ChatThread $thread, int $after = 0): array
    {
        $messages = $thread->messages()
            ->where(function ($query) use ($after) {
                $query->where('id', '>', $after)
                    ->orWhereIn('status', [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_STREAMING]);
            })
            ->with('attachments')
            ->get();

        $confirmations = $thread->confirmations()
            ->where('status', ChatConfirmation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->get();

        return [
            'thread' => $this->threadToClient($thread),
            'messages' => $messages->map(fn (ChatMessage $m) => self::messageToClient($m))->values()->all(),
            'confirmations' => $confirmations->map(fn (ChatConfirmation $c) => $c->toClientArray())->values()->all(),
            'busy' => $messages->contains(fn (ChatMessage $m) => in_array($m->status, [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_STREAMING], true)),
            'quota' => $thread->isOpen() ? $this->quotas->check($thread) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function threadToClient(ChatThread $thread): array
    {
        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'status' => $thread->status,
            'company_id' => $thread->company_id,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'created_at' => $thread->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function messageToClient(ChatMessage $message): array
    {
        $tools = [];

        if ($message->role === ChatMessage::ROLE_ASSISTANT) {
            foreach ((array) $message->content as $block) {
                if (($block['type'] ?? null) === 'mcp_tool_use' && isset($block['name'])) {
                    $tools[] = (string) $block['name'];
                }
            }
        }

        return [
            'id' => $message->id,
            'role' => $message->role,
            'kind' => $message->kind,
            'text' => (string) $message->text,
            'status' => $message->status,
            'error_code' => $message->error_code,
            'tools' => array_values(array_unique($tools)),
            'attachments' => $message->relationLoaded('attachments')
                ? $message->attachments->map(fn (ChatAttachment $a) => $a->toClientArray())->values()->all()
                : [],
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    public function event(User $user, string $event, ?ChatThread $thread = null, ?string $page = null, ?string $promptKey = null): void
    {
        ChatEvent::create([
            'user_id' => $user->getKey(),
            'thread_id' => $thread?->id,
            'event' => $event,
            'page' => $page !== null ? mb_substr($page, 0, 64) : null,
            'prompt_key' => $promptKey !== null ? mb_substr($promptKey, 0, 64) : null,
            'created_at' => now(),
        ]);
    }
}

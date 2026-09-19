<?php

namespace App\Support\Assistant;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Assistant\AssistantQuotas;
use App\Support\Impersonation;

/**
 * Показывать ли помощника этому человеку на этой странице — общий prop Inertia.
 *
 * null — помощника нет вовсе (гость, сотрудник без юрлиц, помощник выключен,
 * кончился баланс, исчерпан месячный предел). Иконка при null не рендерится:
 * недоступный сервис не рекламируют.
 */
final class AssistantPresence
{
    public function __construct(
        private readonly AssistantAvailability $availability,
        private readonly AssistantQuotas $quotas,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forUser(?User $user): ?array
    {
        if ($user === null || ! $this->availability->isAvailable()) {
            return null;
        }

        if ($this->quotas->orgMonthlyExceeded()) {
            return null;
        }

        // Клиент — тот, у кого есть юрлицо; менеджер в режиме просмотра видит
        // помощника глазами клиента и может показать его по телефону.
        if (! Impersonation::active() && ! $user->companies()->exists()) {
            return null;
        }

        $openThread = ChatThread::query()
            ->forUser($user)
            ->open()
            ->orderByDesc('last_message_at')
            ->first(['id', 'title', 'last_message_at']);

        $unread = $openThread === null ? 0 : ChatMessage::query()
            ->where('thread_id', $openThread->id)
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->where('status', ChatMessage::STATUS_DONE)
            ->where('created_at', '>', now()->subMinutes(30))
            ->count();

        return [
            'available' => true,
            'thread_id' => $openThread?->id,
            'unread' => min($unread, 9),
            'bubbles' => (array) config('assistant.bubbles', []),
            'voice' => (bool) config('assistant.voice.browser', true),
            'attachments' => [
                'mimes' => array_keys((array) config('assistant.attachments.mimes', [])),
                'max_size_kb' => (int) config('assistant.attachments.max_size_kb', 20480),
                'max_per_message' => (int) config('assistant.attachments.max_per_message', 5),
            ],
        ];
    }
}

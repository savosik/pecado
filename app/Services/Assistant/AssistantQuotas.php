<?php

namespace App\Services\Assistant;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use Illuminate\Support\Facades\Cache;

/**
 * Потолки расходов, кроме лимита в консоли Anthropic.
 *
 * Персональная квота (ходы и токены юрлица за сутки, токены на тред) видна
 * одному клиенту: иконка остаётся, диалог говорит, когда квота обновится.
 * Месячный предел организации — общий: при его исчерпании помощник исчезает
 * у всех (через AssistantAvailability).
 */
final class AssistantQuotas
{
    public const ORG_KEY = 'assistant:org-monthly-usd';

    /**
     * Почему клиенту сейчас нельзя сделать ход; null — можно.
     *
     * @return array{code: string, message: string}|null
     */
    public function check(ChatThread $thread): ?array
    {
        $quotas = (array) config('assistant.quotas', []);

        $threadMax = (float) ($quotas['thread_max_usd'] ?? 0);

        if ($threadMax > 0 && (float) $thread->cost >= $threadMax) {
            return [
                'code' => 'thread_limit',
                'message' => 'Этот разговор стал слишком длинным. Начните новый — я помню, о чём мы говорили.',
            ];
        }

        $dailyTurns = (int) ($quotas['company_daily_turns'] ?? 0);
        $dailyUsd = (float) ($quotas['company_daily_usd'] ?? 0);

        if ($dailyTurns > 0 || $dailyUsd > 0) {
            $today = $this->todayUsage($thread->user_id);

            if ($dailyTurns > 0 && $today['turns'] >= $dailyTurns) {
                return [
                    'code' => 'daily_turns',
                    'message' => 'На сегодня лимит сообщений исчерпан, завтра помощник снова на связи. Срочное — задайте вопрос менеджеру.',
                ];
            }

            if ($dailyUsd > 0 && $today['usd'] >= $dailyUsd) {
                return [
                    'code' => 'daily_budget',
                    'message' => 'На сегодня лимит помощника исчерпан, завтра он снова на связи. Срочное — задайте вопрос менеджеру.',
                ];
            }
        }

        return null;
    }

    /**
     * Месячный предел организации исчерпан — помощник должен исчезнуть у всех.
     * Считается по стоимости ходов текущего месяца, кешируется на 5 минут.
     */
    public function orgMonthlyExceeded(): bool
    {
        $limit = (float) config('assistant.quotas.org_monthly_usd', 0);

        if ($limit <= 0) {
            return false;
        }

        return $this->orgMonthlyUsd() >= $limit;
    }

    public function orgMonthlyUsd(): float
    {
        return (float) Cache::remember(self::ORG_KEY, now()->addMinutes(5), function (): float {
            return (float) ChatMessage::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('cost');
        });
    }

    /** Сбросить кеш месячной суммы — после каждого хода, чтобы предел ловился без задержки. */
    public function forgetOrgCache(): void
    {
        Cache::forget(self::ORG_KEY);
    }

    /**
     * @return array{turns: int, usd: float}
     */
    private function todayUsage(int $userId): array
    {
        $row = ChatMessage::query()
            ->join('chat_threads', 'chat_threads.id', '=', 'chat_messages.thread_id')
            ->where('chat_threads.user_id', $userId)
            ->where('chat_messages.role', ChatMessage::ROLE_ASSISTANT)
            ->where('chat_messages.status', ChatMessage::STATUS_DONE)
            ->where('chat_messages.created_at', '>=', now()->startOfDay())
            ->selectRaw('COUNT(*) as turns, COALESCE(SUM(chat_messages.cost), 0) as usd')
            ->first();

        return ['turns' => (int) ($row->turns ?? 0), 'usd' => (float) ($row->usd ?? 0)];
    }
}

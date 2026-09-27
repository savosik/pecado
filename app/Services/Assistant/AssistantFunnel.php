<?php

namespace App\Services\Assistant;

use App\Models\ChatEvent;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ClientAgentCall;
use App\Models\User;
use App\Services\Client\Api\Usage\UsageRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Воронка помощника для экрана «ИИ-агенты клиентов»: видели → открыли →
 * написали → оформили, стоимость, доля эскалаций менеджеру, какие реплики
 * ведут в диалог.
 *
 * Это и есть отчёт для спора «агенты никому не нужны»: не «сколько вызовов»,
 * а какая доля клиентов заговорила с агентом и что из этого вышло.
 */
final class AssistantFunnel
{
    /**
     * @param  Builder<User>  $clients  охват сотрудника (мои / отдел)
     * @return array<string, mixed>
     */
    public function build(Builder $clients, CarbonImmutable $since): array
    {
        $userIds = (clone $clients)->select('users.id');

        $distinct = fn (array $events): int => (int) ChatEvent::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('event', $events)
            ->where('created_at', '>=', $since)
            ->distinct('user_id')
            ->count('user_id');

        $shown = $distinct([ChatEvent::SHOWN, ChatEvent::BUBBLE_SHOWN]);
        $opened = $distinct([ChatEvent::OPENED, ChatEvent::BUBBLE_CLICKED, ChatEvent::FIRST_MESSAGE]);
        $wrote = $distinct([ChatEvent::FIRST_MESSAGE]);
        $confirmed = $distinct([ChatEvent::CONFIRMED_ACTION]);

        $threads = ChatThread::query()
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', $since);

        $threadsCount = (clone $threads)->count();
        $cost = (float) (clone $threads)->sum('cost');

        $turns = (int) ChatMessage::query()
            ->whereIn('thread_id', (clone $threads)->select('id'))
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->where('status', ChatMessage::STATUS_DONE)
            ->count();

        $withTurns = (int) ChatMessage::query()
            ->whereIn('thread_id', (clone $threads)->select('id'))
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->where('status', ChatMessage::STATUS_DONE)
            ->distinct('thread_id')
            ->count('thread_id');

        // Эскалация — вопрос менеджеру из чата: вызов с токеном треда.
        $escalated = (int) ClientAgentCall::query()
            ->whereIn('token_id', (clone $threads)->whereNotNull('token_id')->select('token_id'))
            ->where('agent', UsageRecorder::AGENT_WEB_ASSISTANT)
            ->where('operation', 'questions.create')
            ->where('ok', true)
            ->where('created_at', '>=', $since)
            ->distinct('token_id')
            ->count('token_id');

        $orders = (int) ClientAgentCall::query()
            ->whereIn('token_id', (clone $threads)->whereNotNull('token_id')->select('token_id'))
            ->where('agent', UsageRecorder::AGENT_WEB_ASSISTANT)
            ->whereIn('operation', ['orders.create', 'checkout.submit'])
            ->where('ok', true)
            ->where('created_at', '>=', $since)
            ->count();

        $bubbles = ChatEvent::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('event', [ChatEvent::BUBBLE_SHOWN, ChatEvent::BUBBLE_CLICKED])
            ->where('created_at', '>=', $since)
            ->whereNotNull('prompt_key')
            ->select('prompt_key', 'event', DB::raw('COUNT(*) as n'))
            ->groupBy('prompt_key', 'event')
            ->get()
            ->groupBy('prompt_key')
            ->map(function ($rows, string $key): array {
                $shownN = (int) $rows->firstWhere('event', ChatEvent::BUBBLE_SHOWN)?->n;
                $clickedN = (int) $rows->firstWhere('event', ChatEvent::BUBBLE_CLICKED)?->n;

                return [
                    'key' => $key,
                    'shown' => $shownN,
                    'clicked' => $clickedN,
                    'ctr' => $shownN > 0 ? round($clickedN / $shownN * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('shown')
            ->take(10)
            ->values()
            ->all();

        return [
            'funnel' => [
                ['key' => 'shown', 'label' => 'Видели иконку', 'value' => $shown],
                ['key' => 'opened', 'label' => 'Открыли диалог', 'value' => $opened],
                ['key' => 'wrote', 'label' => 'Написали', 'value' => $wrote],
                ['key' => 'confirmed', 'label' => 'Подтвердили действие', 'value' => $confirmed],
            ],
            'threads' => $threadsCount,
            'turns' => $turns,
            'orders' => $orders,
            'cost_usd' => round($cost, 2),
            'cost_per_thread_usd' => $withTurns > 0 ? round($cost / $withTurns, 3) : 0.0,
            'escalated' => $escalated,
            'escalation_share' => $withTurns > 0 ? round($escalated / $withTurns * 100, 1) : 0.0,
            'bubbles' => $bubbles,
        ];
    }
}

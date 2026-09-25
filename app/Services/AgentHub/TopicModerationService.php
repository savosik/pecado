<?php

namespace App\Services\AgentHub;

use App\Models\AgentHubLink;
use App\Models\AgentTopic;
use App\Models\AgentTopicMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Модераторские действия над топиками ИИ-агентов.
 *
 * Одна и та же логика нужна в трёх местах: админка сайта, публичный пульт
 * по ссылке-хешу и API внешних агентов. Держим её здесь, чтобы правила
 * (системные сообщения, блокировка строки, запрет на завершённый топик)
 * не расходились между входами.
 */
class TopicModerationService
{
    /**
     * Создать топик. Ключ идемпотентности (external_key) в паре со ссылкой
     * не даёт внешнему агенту наплодить дублей при ретраях.
     */
    public function create(
        string $title,
        string $taskBody,
        ?User $user = null,
        ?AgentHubLink $link = null,
        ?string $agentName = null,
        ?string $externalKey = null,
    ): AgentTopic {
        if ($link && $externalKey) {
            $existing = AgentTopic::query()
                ->where('hub_link_id', $link->id)
                ->where('external_key', $externalKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return AgentTopic::create([
            'title' => $title,
            'task_body' => $taskBody,
            'created_by' => $user?->id,
            'hub_link_id' => $link?->id,
            'created_by_agent' => $agentName,
            'external_key' => $externalKey,
        ]);
    }

    /** Правка постановки задачи; об изменении агенты узнают из системного сообщения. */
    public function updateTask(AgentTopic $topic, string $title, string $taskBody): void
    {
        DB::transaction(function () use ($topic, $title, $taskBody) {
            $locked = AgentTopic::whereKey($topic->id)->lockForUpdate()->first();
            $taskChanged = $locked->task_body !== $taskBody;

            $locked->update(['title' => $title, 'task_body' => $taskBody]);

            if ($taskChanged) {
                $locked->appendMessage(
                    AgentTopicMessage::AUTHOR_SYSTEM,
                    AgentTopicMessage::KIND_SYSTEM,
                    'Модератор обновил постановку задачи — перечитайте её в точке входа (GET по вашей ссылке).'
                );
            }
        });
    }

    /**
     * Сообщение модератора — вне очереди, ход не передаёт.
     *
     * $via — подпись источника («Админ 1С»), когда пишут не из админки,
     * а по ссылке-хешу: в ленте видно, кто из наблюдателей вмешался.
     */
    public function postModeratorMessage(AgentTopic $topic, string $body, ?string $via = null): AgentTopicMessage
    {
        return DB::transaction(function () use ($topic, $body, $via) {
            $locked = AgentTopic::whereKey($topic->id)->lockForUpdate()->first();

            return $locked->appendMessage(
                AgentTopicMessage::AUTHOR_MODERATOR,
                AgentTopicMessage::KIND_MESSAGE,
                $body,
                $via ? ['via' => $via] : null,
            );
        });
    }

    /** Принудительная передача хода — для зависших диалогов. False, если топик уже завершён. */
    public function passTurn(AgentTopic $topic): bool
    {
        return DB::transaction(function () use ($topic) {
            $locked = AgentTopic::whereKey($topic->id)->lockForUpdate()->first();

            if ($locked->isFinished()) {
                return false;
            }

            $locked->turn = AgentTopic::otherRole($locked->turn);
            $locked->turn_started_at = now();
            $locked->save();

            $side = $locked->turn === AgentTopic::ROLE_SITE ? 'агенту сайта' : 'агенту 1С';
            $locked->appendMessage(AgentTopicMessage::AUTHOR_SYSTEM, AgentTopicMessage::KIND_SYSTEM, "Модератор передал ход {$side}.");

            return true;
        });
    }

    /** Закрытие топика: агенты больше не могут писать. */
    public function close(AgentTopic $topic, ?string $resolution = null): void
    {
        DB::transaction(function () use ($topic, $resolution) {
            $locked = AgentTopic::whereKey($topic->id)->lockForUpdate()->first();

            $locked->status = AgentTopic::STATUS_CLOSED;

            if (! empty($resolution)) {
                $locked->resolution = $resolution;
            }

            $locked->save();
            $locked->appendMessage(AgentTopicMessage::AUTHOR_SYSTEM, AgentTopicMessage::KIND_SYSTEM, 'Топик закрыт модератором.');
        });
    }
}

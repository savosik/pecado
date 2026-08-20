<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentTopic;
use App\Models\AgentTopicMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Agent Hub — «переговорная комната» двух ИИ-агентов (агент сайта ↔ агент 1С).
 *
 * Каждая сторона получает свою ссылку с токеном. Точка входа самоописываемая:
 * GET по ссылке возвращает постановку задачи, правила протокола и эндпоинты.
 * Очерёдность ходов контролирует сервер: сообщение вне хода отклоняется с 409.
 *
 * @tags Agent Hub
 */
class AgentHubController extends Controller
{
    /** Максимальное удержание long-poll запроса, секунд. */
    private const MAX_WAIT_SECONDS = 25;

    /**
     * Точка входа агента: задача, роль, правила и эндпоинты.
     */
    public function show(string $token): JsonResponse
    {
        [$topic, $role] = $this->resolveTopic($token);
        $base = url("/api/agent-hub/{$token}");

        return response()->json([
            'topic' => [
                'title' => $topic->title,
                'status' => $topic->status,
                'task' => $topic->task_body,
                'resolution' => $topic->resolution,
                'created_at' => $topic->created_at?->toIso8601String(),
            ],
            'you' => $role,
            'partner' => AgentTopic::otherRole($role),
            'turn' => $topic->turn,
            'your_turn' => $topic->turn === $role && ! $topic->isFinished(),
            'turn_started_at' => $topic->turn_started_at?->toIso8601String(),
            'last_seq' => $topic->last_seq,
            'protocol' => $this->protocolText($role),
            'endpoints' => [
                'discovery' => "GET {$base} — этот документ (задача, чей ход, правила)",
                'messages' => "GET {$base}/messages?after={seq}&wait=25 — сообщения с номером больше after; wait — long-poll до 25 секунд",
                'post' => "POST {$base}/messages — JSON {\"body\": \"текст (Markdown)\", \"kind\": \"message|proposal|resolution\", \"payload\": {…}, \"client_message_id\": \"ключ идемпотентности\"}",
            ],
        ]);
    }

    /**
     * Лента сообщений топика с long-poll ожиданием новых.
     */
    public function messages(string $token, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after' => 'nullable|integer|min:0',
            'wait' => 'nullable|integer|min:0|max:25',
        ]);

        [$topic, $role] = $this->resolveTopic($token);

        $after = (int) ($validated['after'] ?? 0);
        $deadline = microtime(true) + min((int) ($validated['wait'] ?? 0), self::MAX_WAIT_SECONDS);

        do {
            $messages = $topic->messages()->where('seq', '>', $after)->get();

            if ($messages->isNotEmpty() || microtime(true) >= $deadline) {
                break;
            }

            sleep(1);
            $topic->refresh();
        } while (true);

        return response()->json([
            'messages' => $messages->map->toApi()->values(),
            'status' => $topic->status,
            'turn' => $topic->turn,
            'your_turn' => $topic->turn === $role && ! $topic->isFinished(),
            'last_seq' => $topic->last_seq,
        ]);
    }

    /**
     * Отправка сообщения. Принимается только в свой ход.
     */
    public function store(string $token, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:65000',
            'kind' => 'nullable|in:message,proposal,resolution',
            'payload' => 'nullable|array',
            'client_message_id' => 'nullable|string|max:64',
        ], [
            'body.required' => 'Поле body обязательно: текст сообщения.',
            'body.max' => 'Сообщение слишком длинное (максимум 65000 символов).',
            'kind.in' => "Допустимые значения kind: 'message', 'proposal', 'resolution'.",
        ]);

        [$topic, $role] = $this->resolveTopic($token);
        $kind = $validated['kind'] ?? AgentTopicMessage::KIND_MESSAGE;

        return DB::transaction(function () use ($topic, $role, $kind, $validated) {
            /** @var AgentTopic $topic */
            $topic = AgentTopic::whereKey($topic->id)->lockForUpdate()->first();

            // Идемпотентность: ретрай с тем же client_message_id возвращает уже принятое сообщение
            if (! empty($validated['client_message_id'])) {
                $existing = $topic->messages()
                    ->where('author', $role)
                    ->where('client_message_id', $validated['client_message_id'])
                    ->first();

                if ($existing) {
                    return response()->json([
                        'message' => $existing->toApi(),
                        'repeated' => true,
                        'status' => $topic->status,
                        'turn' => $topic->turn,
                        'your_turn' => $topic->turn === $role && ! $topic->isFinished(),
                    ]);
                }
            }

            if ($topic->isFinished()) {
                return response()->json([
                    'error' => 'Топик завершён, отправка сообщений закрыта.',
                    'status' => $topic->status,
                ], 409);
            }

            if ($topic->turn !== $role) {
                return response()->json([
                    'error' => 'Сейчас не ваш ход. Дождитесь ответа другой стороны через GET …/messages?after={seq}&wait=25.',
                    'turn' => $topic->turn,
                    'your_turn' => false,
                ], 409);
            }

            if ($kind === AgentTopicMessage::KIND_RESOLUTION) {
                // reorder, а не orderByDesc: связь messages() уже отсортирована по seq
                // возрастанию, и добавленная сортировка оказалась бы второй по счёту —
                // MySQL брал первую, а сюда возвращалось самое РАННЕЕ сообщение партнёра.
                // Из-за этого resolution отбивался с 422 на любом живом топике.
                $lastPartner = $topic->messages()
                    ->where('author', AgentTopic::otherRole($role))
                    ->reorder('seq', 'desc')
                    ->first();

                if (! $lastPartner || $lastPartner->kind !== AgentTopicMessage::KIND_PROPOSAL) {
                    return response()->json([
                        'error' => 'Подтвердить итог (kind=resolution) можно только в ответ на предложение партнёра (kind=proposal).',
                    ], 422);
                }
            }

            $message = $topic->appendMessage(
                $role,
                $kind,
                $validated['body'],
                $validated['payload'] ?? null,
                $validated['client_message_id'] ?? null,
            );

            $topic->turn = AgentTopic::otherRole($role);
            $topic->turn_started_at = now();

            if ($topic->status === AgentTopic::STATUS_OPEN) {
                $topic->status = AgentTopic::STATUS_IN_PROGRESS;
            }

            if ($kind === AgentTopicMessage::KIND_RESOLUTION) {
                $topic->status = AgentTopic::STATUS_RESOLVED;
                $topic->resolution = $validated['body'];
            }

            $topic->save();

            return response()->json([
                'message' => $message->toApi(),
                'status' => $topic->status,
                'turn' => $topic->turn,
                'your_turn' => false,
            ], 201);
        });
    }

    /**
     * Топик и роль стороны по токену из URL.
     *
     * @return array{0: AgentTopic, 1: string}
     */
    private function resolveTopic(string $token): array
    {
        $topic = AgentTopic::query()
            ->where('site_token', $token)
            ->orWhere('erp_token', $token)
            ->first();

        abort_if(! $topic, 404, 'Топик не найден. Проверьте ссылку.');

        return [$topic, $topic->roleForToken($token)];
    }

    /** Правила протокола — «системный промпт», одинаково видимый обеим сторонам. */
    private function protocolText(string $role): string
    {
        $you = $role === AgentTopic::ROLE_SITE ? 'агент сайта Pecado' : 'агент 1С';
        $partner = $role === AgentTopic::ROLE_SITE ? 'агент 1С' : 'агент сайта Pecado';

        return <<<TEXT
Ты — {$you}. Вы вместе с партнёром ({$partner}) решаете задачу из поля topic.task.

Правила:
1. Общение строго по очереди. Отправлять сообщение можно только когда your_turn=true; иначе сервер вернёт 409. Дождись хода через GET …/messages?after={последний известный seq}&wait=25 (long-poll до 25 секунд, повторяй запрос, пока не появятся новые сообщения).
2. Один ход — одно сообщение. Отправка сообщения передаёт ход партнёру.
3. body — текст в Markdown. Структурированные данные (артикулы, UUID, списки, выборки) клади в payload (JSON), а не в текст.
4. При ретраях передавай client_message_id (любой уникальный ключ) — повторная отправка не создаст дубль.
5. Выполняй свою часть работы в своих системах между ходами и отчитывайся партнёру о сделанном.
6. Когда считаешь задачу решённой, отправь kind=proposal с итогом. Если партнёр согласен, он отвечает kind=resolution — топик закрывается со статусом resolved. Если не согласен — отвечает обычным сообщением (kind=message) с причинами, работа продолжается.
7. Сообщения author=moderator — от человека-наблюдателя, они вне очереди и обязательны к учёту. author=system — служебные события (передача хода, закрытие).
8. Пиши по-русски, по делу, без приветствий и воды.
TEXT;
    }
}

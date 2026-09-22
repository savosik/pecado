<?php

namespace App\Mcp\Tools\Client;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Support\Client\ClientApiSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Память помощника: прошлые разговоры этого клиента.
 *
 * Только для чата-помощника (токен вида assistant): резюме последних тредов
 * и поиск по репликам, чтобы «то, что ты предложил» из вчерашнего разговора
 * нашлось, а не переспрашивалось. Собственным агентам клиента (Claude Code,
 * Cursor) инструмент недоступен — у них своя память.
 */
#[IsReadOnly]
class ClientMemory extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-memory';

    protected string $description = 'Прошлые разговоры этого клиента с помощником: резюме последних и поиск по репликам. '
        .'Вызывайте, когда клиент ссылается на прошлый разговор («то, что ты предложил», «как в прошлый раз»), '
        .'а в текущем треде этого нет. Только для помощника в кабинете.';

    /** Сколько последних тредов отдавать без запроса. */
    private const RECENT = 5;

    /** Сколько реплик находить по запросу. */
    private const MATCHES = 12;

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Слово или фраза для поиска по прошлым репликам (артикул, товар, тема). Пусто — только резюме последних разговоров.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return $this->refuse('unauthorized', 'Не удалось определить клиента по токену.');
        }

        if (! ClientApiSource::isAssistant()) {
            return $this->refuse('assistant_only', 'Память разговоров доступна только помощнику в кабинете.');
        }

        $currentThreadId = ChatThread::query()
            ->where('user_id', $actor->getKey())
            ->where('token_id', ClientApiSource::tokenId())
            ->value('id');

        $threads = ChatThread::query()
            ->where('user_id', $actor->getKey())
            ->when($currentThreadId !== null, fn ($q) => $q->where('id', '!=', $currentThreadId))
            ->whereHas('messages', fn ($q) => $q->where('role', ChatMessage::ROLE_ASSISTANT)->where('status', ChatMessage::STATUS_DONE))
            ->orderByDesc('last_message_at')
            ->limit(self::RECENT)
            ->get();

        $recent = $threads->map(function (ChatThread $t): array {
            $summary = $t->summary;

            if ($summary === null || trim($summary) === '') {
                // Тред ещё не закрыт — резюме нет, покажем последний ответ помощника.
                $summary = $t->messages()
                    ->where('role', ChatMessage::ROLE_ASSISTANT)
                    ->where('status', ChatMessage::STATUS_DONE)
                    ->orderByDesc('id')
                    ->value('text');
                $summary = $summary !== null ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $summary)), 0, 500) : null;
            }

            return [
                'thread_id' => $t->id,
                'when' => $t->last_message_at?->format('d.m.Y H:i'),
                'title' => $t->title,
                'summary' => $summary,
            ];
        })->values()->all();

        $q = trim((string) $request->get('q', ''));
        $matches = [];

        if ($q !== '') {
            $rows = ChatMessage::query()
                ->join('chat_threads', 'chat_threads.id', '=', 'chat_messages.thread_id')
                ->where('chat_threads.user_id', $actor->getKey())
                ->when($currentThreadId !== null, fn ($query) => $query->where('chat_threads.id', '!=', $currentThreadId))
                ->where('chat_messages.status', ChatMessage::STATUS_DONE)
                ->where('chat_messages.kind', ChatMessage::KIND_MESSAGE)
                ->where('chat_messages.text', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orderByDesc('chat_messages.id')
                ->limit(self::MATCHES)
                ->get(['chat_messages.thread_id', 'chat_messages.role', 'chat_messages.text', 'chat_messages.created_at']);

            foreach ($rows as $row) {
                $matches[] = [
                    'thread_id' => $row->thread_id,
                    'when' => $row->created_at?->format('d.m.Y H:i'),
                    'who' => $row->role === ChatMessage::ROLE_USER ? 'клиент' : 'помощник',
                    'text' => self::snippet((string) $row->text, $q),
                ];
            }
        }

        return $this->payload([
            'recent' => $recent,
            'matches' => $matches,
            'hint' => $recent === [] && $matches === []
                ? 'Прошлых разговоров нет — это первый.'
                : 'Опирайтесь на резюме и реплики; артикулы и суммы перепроверяйте текущими операциями — цены и остатки могли измениться.',
        ]);
    }

    /** Кусок текста вокруг найденного слова, чтобы не отдавать ответ целиком. */
    private static function snippet(string $text, string $q): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $text));
        $pos = mb_stripos($flat, $q);

        if ($pos === false || mb_strlen($flat) <= 400) {
            return mb_substr($flat, 0, 400);
        }

        $start = max(0, $pos - 150);

        return ($start > 0 ? '…' : '').mb_substr($flat, $start, 400).(mb_strlen($flat) > $start + 400 ? '…' : '');
    }
}

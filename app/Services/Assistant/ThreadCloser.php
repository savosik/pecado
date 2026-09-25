<?php

namespace App\Services\Assistant;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ClientAssistantNote;
use App\Services\Assistant\Gateway\AssistantGateway;
use App\Services\Assistant\Gateway\GatewayException;
use Throwable;

/**
 * Закрытие тредов по бездействию и память клиента.
 *
 * Тред, в котором молчат дольше `assistant.threads.idle_hours`, закрывается:
 * токен отзывается, модель одним дешёвым запросом пишет резюме треда и
 * обновляет заметку о клиенте. Заметка — то, что помощник «помнит» в
 * следующем разговоре; резюме — для менеджера в CRM.
 */
final class ThreadCloser
{
    public function __construct(
        private readonly AssistantGateway $gateway,
        private readonly ThreadService $threads,
        private readonly CostCalculator $cost,
    ) {}

    /**
     * @return int сколько тредов закрыто
     */
    public function closeIdle(): int
    {
        $idleHours = max(1, (int) config('assistant.threads.idle_hours', 24));

        $idle = ChatThread::query()
            ->open()
            ->where('last_message_at', '<', now()->subHours($idleHours))
            ->orderBy('last_message_at')
            ->limit(200)
            ->get();

        $closed = 0;

        foreach ($idle as $thread) {
            if ($this->threads->isBusy($thread)) {
                continue;
            }

            $this->close($thread);
            $closed++;
        }

        return $closed;
    }

    /**
     * Закрыть тред и дописать память. Сбой модели не мешает закрытию:
     * без резюме тред просто закрыт, заметка остаётся прежней.
     */
    public function close(ChatThread $thread): void
    {
        $this->threads->close($thread);

        try {
            $this->remember($thread);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Резюме треда и обновлённая заметка одним запросом без инструментов.
     */
    public function remember(ChatThread $thread): void
    {
        $transcript = $this->transcript($thread);

        if ($transcript === '') {
            return;
        }

        $note = ClientAssistantNote::query()->where('user_id', $thread->user_id)->first();
        $current = $note?->content ?? '';

        $prompt = <<<TEXT
Ниже — разговор клиента оптового магазина Pecado с помощником в кабинете и текущая заметка помощника об этом клиенте.

Сделай две вещи и ответь строго в формате:

РЕЗЮМЕ:
<2–4 предложения только о фактах: что клиент хотел, что реально сделано (номер заказа, корзина, документ, вопрос менеджеру — только если операция была вызвана и подтверждена), о чём клиент прямо попросил вернуться позже. Предложения помощника, на которые клиент не ответил, не записывай ни как «открытые вопросы», ни как «ожидает ответа» — это не обязательства. Не записывай статусы заказов, суммы долга и остатки: они меняются и читаются инструментами заново.>

ЗАМЕТКА:
<обновлённая заметка о клиенте, а не о сайте: как обращаться, юрлицо, что и как часто заказывает, предпочтения по доставке и оплате, договорённости, интересы. Сохрани из текущей заметки всё ещё актуальное о клиенте, добавь новое, убери устаревшее. Не пиши в заметку: статусы и суммы, «открытые вопросы» и ожидания ответов, ошибки и отказы инструментов, ограничения сайта («не работает», «раздела нет») — их чинят, а заметка живёт долго. До 2000 знаков, без токенов, паролей и телефонов. Если добавить нечего — повтори текущую заметку.>

Текущая заметка:
{$current}

Разговор:
{$transcript}
TEXT;

        try {
            $response = $this->gateway->create([
                'model' => (string) config('assistant.model'),
                'maxTokens' => 2000,
                'outputConfig' => ['effort' => 'low'],
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        } catch (GatewayException $e) {
            report($e);

            return;
        }

        $text = ChatMessage::textOf(is_array($response['content'] ?? null) ? $response['content'] : []);
        [$summary, $updated] = self::parse($text);

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $cost = $this->cost->forUsage(isset($response['model']) ? (string) $response['model'] : null, $usage);

        $thread->forceFill([
            'summary' => $summary !== '' ? mb_substr($summary, 0, 2000) : $thread->summary,
            'cost' => (float) $thread->cost + $cost,
        ])->save();

        if ($updated !== '' && $updated !== trim($current)) {
            ClientAssistantNote::updateOrCreate(
                ['user_id' => $thread->user_id],
                [
                    'content' => mb_substr($updated, 0, ClientAssistantNote::MAX_LENGTH),
                    'version' => ($note?->version ?? 0) + 1,
                    'updated_by' => ClientAssistantNote::BY_MODEL,
                ],
            );
        }
    }

    /**
     * @return array{0: string, 1: string} резюме, заметка
     */
    public static function parse(string $text): array
    {
        $summary = '';
        $note = '';

        if (preg_match('/РЕЗЮМЕ:\s*(.*?)\s*ЗАМЕТКА:\s*(.*)$/su', $text, $m) === 1) {
            $summary = trim($m[1]);
            $note = trim($m[2]);
        } elseif (trim($text) !== '') {
            $summary = trim($text);
        }

        return [$summary, $note];
    }

    private function transcript(ChatThread $thread): string
    {
        $lines = [];

        foreach ($thread->messages()->where('status', ChatMessage::STATUS_DONE)->get(['role', 'kind', 'text']) as $message) {
            $text = trim((string) $message->text);

            if ($text === '' || $message->kind === ChatMessage::KIND_NOTE) {
                continue;
            }

            $who = $message->role === ChatMessage::ROLE_USER ? 'Клиент' : 'Помощник';
            $lines[] = $who.': '.mb_substr($text, 0, 1500);
        }

        return mb_substr(implode("\n", $lines), 0, 24000);
    }
}

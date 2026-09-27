<?php

namespace App\Services\Assistant;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\Assistant\Gateway\AssistantGateway;
use App\Services\Assistant\Gateway\GatewayException;
use Throwable;

/**
 * Один ход модели: собрать запрос из треда, отправить, склеить стрим,
 * сохранить ответ как есть, посчитать стоимость.
 *
 * Частичный текст пишется в базу по мере прихода — клиент опрашивает тред
 * раз в секунду и видит, как ответ печатается. Ошибка шлюза не рушит тред:
 * ход помечается failed, а флаг доступности решает, прятать ли помощника.
 */
final class TurnRunner
{
    /** Как часто сбрасывать частичный текст в базу. */
    private const FLUSH_CHARS = 120;

    private const FLUSH_MS = 500;

    public function __construct(
        private readonly AssistantGateway $gateway,
        private readonly RequestBuilder $builder,
        private readonly AssistantTokenIssuer $tokens,
        private readonly AssistantAvailability $availability,
        private readonly AttachmentService $attachments,
        private readonly CostCalculator $cost,
        private readonly AssistantQuotas $quotas,
    ) {}

    public function run(int $assistantMessageId): void
    {
        $message = ChatMessage::query()->with('thread')->find($assistantMessageId);

        if ($message === null || $message->status !== ChatMessage::STATUS_PENDING) {
            return;
        }

        /** @var ChatThread $thread */
        $thread = $message->thread;

        if (! $thread->isOpen()) {
            $this->fail($message, 'closed', 'Разговор закрыт. Начните новый.');

            return;
        }

        if (! $this->availability->isAvailable()) {
            $this->fail($message, 'unavailable', 'Не удалось ответить, попробуйте позже.');

            return;
        }

        try {
            $this->attachments->syncToAnthropic($thread);
            $token = $this->tokens->forThread($thread);
            $request = $this->builder->build($thread, $token);

            $message->forceFill(['status' => ChatMessage::STATUS_STREAMING, 'model' => $request['model']])->save();

            $accumulator = new StreamAccumulator;
            $partial = '';
            $lastFlush = hrtime(true);

            foreach ($this->gateway->stream($request) as $event) {
                $accumulator->accumulate($event);
                $chunk = $accumulator->takePendingText();

                if ($chunk === '') {
                    continue;
                }

                $partial .= $chunk;
                $elapsedMs = (hrtime(true) - $lastFlush) / 1_000_000;

                if (mb_strlen($chunk) >= self::FLUSH_CHARS || $elapsedMs >= self::FLUSH_MS) {
                    $message->forceFill(['text' => $partial])->save();
                    $lastFlush = hrtime(true);
                }
            }

            if (! $accumulator->started()) {
                throw new GatewayException(GatewayException::UNKNOWN, 'Ответ Anthropic пуст.');
            }

            $this->complete($thread, $message, $accumulator->message());
        } catch (GatewayException $e) {
            $this->availability->observe($e);
            report($e);

            $this->fail(
                $message,
                $e->kind,
                $e->isTransient()
                    ? 'Не удалось ответить, попробуйте ещё раз через минуту.'
                    : 'Не удалось ответить, попробуйте позже.',
            );
        } catch (Throwable $e) {
            report($e);
            $this->fail($message, 'internal', 'Не удалось ответить, попробуйте позже.');
        } finally {
            $this->quotas->forgetOrgCache();
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function complete(ChatThread $thread, ChatMessage $message, array $response): void
    {
        /** @var list<array<string, mixed>> $content */
        $content = is_array($response['content'] ?? null) ? array_values($response['content']) : [];
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $model = isset($response['model']) ? (string) $response['model'] : $message->model;
        $stopReason = isset($response['stop_reason']) ? (string) $response['stop_reason'] : null;

        $text = ChatMessage::textOf($content);

        if ($stopReason === 'refusal' && $text === '') {
            $text = 'Я не могу помочь с этим запросом. Спросите о заказах, ценах, документах или долге.';
        }

        if ($text === '' && $content !== []) {
            $text = 'Готово.';
        }

        $cost = $this->cost->forUsage($model, $usage);

        $message->forceFill([
            'content' => $content,
            'text' => $text,
            'status' => ChatMessage::STATUS_DONE,
            'error_code' => null,
            'usage' => $usage,
            'cost' => $cost,
            'anthropic_id' => isset($response['id']) ? mb_substr((string) $response['id'], 0, 64) : null,
            'model' => $model,
            'stop_reason' => $stopReason,
        ])->save();

        $compacted = false;

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'compaction') {
                $compacted = true;
            }
        }

        $thread->forceFill([
            'input_tokens' => $thread->input_tokens + (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => $thread->output_tokens + (int) ($usage['output_tokens'] ?? 0),
            'cache_read_tokens' => $thread->cache_read_tokens + (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_write_tokens' => $thread->cache_write_tokens + (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'cost' => (float) $thread->cost + $cost,
            'last_message_at' => now(),
            'compacted_at' => $compacted ? now() : $thread->compacted_at,
        ])->save();
    }

    private function fail(ChatMessage $message, string $code, string $text): void
    {
        $message->forceFill([
            'status' => ChatMessage::STATUS_FAILED,
            'error_code' => mb_substr($code, 0, 64),
            'text' => $text,
            'content' => [],
        ])->save();
    }
}

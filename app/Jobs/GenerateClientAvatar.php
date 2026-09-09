<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Crm\Avatars\ClientAvatarGenerator;
use App\Services\Crm\Avatars\ClientAvatarService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Нарисовать аватарку партнёру.
 *
 * Уникален по партнёру: кнопка в карточке, ночная пачка и повторный заход
 * менеджера не должны сложиться в три оплаченных рисунка одного и того же.
 *
 * Ошибки внутрь очереди не пробрасываются: неудачная генерация — рабочая
 * ситуация (модель перегружена, ключ кончился), и партнёр просто остаётся
 * с инициалами. Считается попытка, дальше решает пауза в конфиге.
 */
class GenerateClientAvatar implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Уникальность держим час: дольше живёт только зависшая очередь. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $clientId,
        public readonly bool $force = false,
    ) {
        $this->onQueue((string) config('crm_avatars.generation.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'crm-avatar:'.$this->clientId;
    }

    public function handle(
        ClientAvatarService $avatars,
        ClientAvatarGenerator $generator,
    ): void {
        if (! config('crm_avatars.enabled') || ! config('crm_avatars.generation.enabled')) {
            return;
        }

        $client = User::query()->find($this->clientId);

        if ($client === null) {
            return;
        }

        $avatar = $avatars->recordFor($client);

        // Ручную аватарку не трогаем никогда: выбор менеджера старше любой
        // автоматики. Готовую ИИ-шную перерисовываем только по явной просьбе.
        if ($avatar->isManual() || ($avatar->hasFile() && ! $this->force)) {
            return;
        }

        if (! $this->force && ! $generator->mayRetry($avatar)) {
            return;
        }

        try {
            $result = $generator->generate($client);

            $avatars->storeGenerated($client, $result['binary'], [
                'prompt' => $result['prompt'],
                'image_model' => $result['image_model'],
                'text_model' => $result['text_model'],
            ]);
        } catch (Throwable $e) {
            $avatars->markFailed($client, $e->getMessage());

            Log::warning('Не удалось нарисовать аватарку партнёра', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

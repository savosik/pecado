<?php

namespace App\Jobs\Assistant;

use App\Services\Assistant\TurnRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ход помощника в очереди: без повторов — повтор после обрыва создал бы второй
 * ответ на ту же реплику. «Один ход на тред» обеспечивает ThreadService::isBusy
 * по статусам ходов, а не замок уникальности: замок живёт в кеше и переживает
 * упавший воркер, статус в базе — нет.
 */
class RunAssistantTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $threadId, public readonly int $messageId)
    {
        // Очередь по умолчанию на проде — RabbitMQ (шина 1С), а ходы помощника
        // обслуживает Horizon: соединение задаётся явно, иначе job ляжет в
        // очередь, которую никто не слушает. В тестах — sync.
        $this->onConnection((string) config('assistant.queue_connection', 'redis'))
            ->onQueue(\App\Services\Assistant\ThreadService::QUEUE);
    }

    public function handle(TurnRunner $runner): void
    {
        $runner->run($this->messageId);
    }
}

<?php

namespace App\Console\Commands\Assistant;

use App\Services\Assistant\AssistantAvailability;
use Illuminate\Console\Command;

/**
 * Плановая проба: пока помощник спрятан, раз в N минут пробуем Anthropic и
 * возвращаем его сами, как только баланс пополнен или прокси ожил. Когда
 * помощник доступен, проба ничего не стоит — запрос не отправляется.
 */
class AssistantProbe extends Command
{
    protected $signature = 'assistant:probe {--force : пробовать, даже если помощник доступен}';

    protected $description = 'Вернуть спрятанного помощника пробным запросом к Anthropic (планировщик)';

    public function handle(AssistantAvailability $availability): int
    {
        if (! $availability->configured()) {
            $this->line('Помощник выключен или ключ не задан — пробовать нечего.');

            return self::SUCCESS;
        }

        if ($availability->isAvailable() && ! $this->option('force')) {
            $this->line('Помощник доступен — проба не нужна.');

            return self::SUCCESS;
        }

        $result = $availability->probe();
        $this->line(($result['ok'] ? 'Вернулся: ' : 'Всё ещё недоступен: ').$result['message']);

        return self::SUCCESS;
    }
}

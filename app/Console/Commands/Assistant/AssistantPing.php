<?php

namespace App\Console\Commands\Assistant;

use App\Services\Assistant\AssistantAvailability;
use Illuminate\Console\Command;

/**
 * Пробный запрос к Anthropic через настроенный прокси: жив ли ключ, отвечает
 * ли API, какая модель ответила. Успех возвращает помощника клиентам, если
 * он был спрятан.
 */
class AssistantPing extends Command
{
    protected $signature = 'assistant:ping';

    protected $description = 'Проверить доступ помощника к Anthropic (ключ, прокси, модель) и обновить флаг доступности';

    public function handle(AssistantAvailability $availability): int
    {
        $this->line('Модель: '.config('assistant.model').', прокси: '.(config('assistant.proxy') ?: 'нет'));

        $started = hrtime(true);
        $result = $availability->probe();
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);

        if (! $result['ok']) {
            $this->error("Не удалось: {$result['message']} ({$ms} мс)");
            $state = $availability->state();
            $this->line('Состояние помощника: '.$state['state'].($state['reason'] ? ' — '.$state['reason'] : ''));

            return self::FAILURE;
        }

        $usage = $result['usage'] ?? [];
        $this->info("Ответила {$result['model']} за {$ms} мс; токены: вход ".($usage['input_tokens'] ?? '?').', выход '.($usage['output_tokens'] ?? '?'));
        $this->line('Состояние помощника: '.$availability->state()['state']);

        return self::SUCCESS;
    }
}

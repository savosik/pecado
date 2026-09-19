<?php

namespace App\Console\Commands\Assistant;

use App\Services\Assistant\AssistantAvailability;
use Illuminate\Console\Command;

/**
 * Показать или переключить флаг доступности помощника руками.
 *
 * `assistant:availability` — состояние; `assistant:availability on|off` —
 * вернуть или спрятать помощника без ожидания пробы планировщика.
 */
class AssistantAvailabilityCommand extends Command
{
    protected $signature = 'assistant:availability {state? : on — вернуть помощника, off — спрятать}';

    protected $description = 'Состояние помощника клиента: показать, вернуть (on) или спрятать (off)';

    public function handle(AssistantAvailability $availability): int
    {
        $state = $this->argument('state');

        if ($state === 'on') {
            $availability->markAvailable();
            $this->info('Помощник возвращён клиентам.');
        } elseif ($state === 'off') {
            $availability->markUnavailable('manual: выключен командой assistant:availability off');
            $this->info('Помощник спрятан у всех клиентов.');
        } elseif ($state !== null) {
            $this->error('Допустимо: on, off или без аргумента.');

            return self::INVALID;
        }

        $current = $availability->state();
        $this->line('Рубильник CLIENT_ASSISTANT_ENABLED: '.(config('assistant.enabled') ? 'включён' : 'выключен')
            .'; ключ: '.((string) config('assistant.api_key') !== '' ? 'задан' : 'нет'));
        $this->line('Состояние: '.$current['state']
            .($current['reason'] ? ' — '.$current['reason'] : '')
            .($current['since'] ? ' (с '.$current['since'].')' : ''));

        return self::SUCCESS;
    }
}

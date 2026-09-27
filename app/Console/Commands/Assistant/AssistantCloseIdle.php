<?php

namespace App\Console\Commands\Assistant;

use App\Services\Assistant\AssistantTokenIssuer;
use App\Services\Assistant\ThreadCloser;
use Illuminate\Console\Command;

/**
 * Закрыть треды помощника, в которых молчат дольше idle_hours: отозвать
 * токены, записать резюме и обновить заметку о клиенте. Заодно деактивировать
 * истёкшие токены помощника, оставшиеся от оборванных воркеров.
 */
class AssistantCloseIdle extends Command
{
    protected $signature = 'assistant:close-idle';

    protected $description = 'Закрыть неактивные треды помощника и дописать память о клиентах';

    public function handle(ThreadCloser $closer, AssistantTokenIssuer $tokens): int
    {
        $closed = $closer->closeIdle();
        $expired = $tokens->deactivateExpired();

        $this->info("Закрыто тредов: {$closed}; деактивировано истёкших токенов: {$expired}.");

        return self::SUCCESS;
    }
}

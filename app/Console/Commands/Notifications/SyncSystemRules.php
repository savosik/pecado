<?php

namespace App\Console\Commands\Notifications;

use App\Services\Notifications\Pulse\SystemRulesSynchronizer;
use Illuminate\Console\Command;

class SyncSystemRules extends Command
{
    protected $signature = 'notifications:sync-system-rules';

    protected $description = 'Завести или обновить системные правила пульта уведомлений';

    public function handle(SystemRulesSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync();

        $this->info(sprintf(
            'Системные правила: создано %d, обновлено %d.',
            $result['created'],
            $result['updated'],
        ));

        $this->line('Включённость и получатели существующих правил не изменялись — они правятся в пульте.');

        return self::SUCCESS;
    }
}

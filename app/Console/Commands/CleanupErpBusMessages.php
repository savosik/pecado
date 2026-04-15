<?php

namespace App\Console\Commands;

use App\Models\ErpBusMessage;
use Illuminate\Console\Command;

/**
 * Удаление старых записей лога ERP-шины.
 *
 * Запускать по расписанию (schedule:run) или вручную.
 * По умолчанию удаляет записи старше 30 дней.
 */
class CleanupErpBusMessages extends Command
{
    protected $signature = 'erp:cleanup-messages {--days=30 : Удалять записи старше N дней}';

    protected $description = 'Удалить старые записи лога сообщений ERP-шины';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $deleted = ErpBusMessage::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Удалено {$deleted} записей старше {$days} дней.");

        return self::SUCCESS;
    }
}

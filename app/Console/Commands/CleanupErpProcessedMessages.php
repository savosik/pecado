<?php

namespace App\Console\Commands;

use App\Models\ErpProcessedMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ретенция журнала дедупликации входящих сообщений 1С.
 *
 * Таблица `erp_processed_messages` росла вечно: записи никогда не удалялись,
 * хотя нужны они ровно на горизонт повторной доставки RabbitMQ — часы, максимум
 * сутки. К августу 2026 это 82 MB чисто технических строк в боевой базе.
 *
 * Архива нет осознанно: в таблице только `message_id`, `event` и `processed_at`,
 * бизнес-содержания в ней нет — payload живёт в `erp_bus_messages`, и вот его
 * мы архивируем (см. CleanupErpBusMessages).
 *
 * Защита от устаревших документов чисткой не ослабляется: она реализована
 * отдельно, через `erp_documents.applied_revision` (ErpRevisionGuard).
 */
class CleanupErpProcessedMessages extends Command
{
    protected $signature = 'erp:cleanup-processed
        {--days= : Удалять записи старше N дней (по умолчанию erp.processed_retention_days)}
        {--chunk=2000 : Размер пачки при удалении}
        {--dry-run : Показать, что будет удалено, ничего не меняя}';

    protected $description = 'Удалить старые записи журнала дедупликации входящих ERP-сообщений';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('erp.processed_retention_days', 14));

        if ($days <= 0) {
            $this->info('Ретенция журнала дедупликации отключена (erp.processed_retention_days = 0).');

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $cutoff = now()->subDays($days);

        $total = ErpProcessedMessage::where('processed_at', '<', $cutoff)->count();
        if ($total === 0) {
            $this->info("Записей старше {$days} дней нет.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Под удаление попадает {$total} записей старше {$days} дней.");

            return self::SUCCESS;
        }

        // Пачками, а не одним DELETE: таблица большая, а длинная транзакция
        // блокировала бы обработку входящих сообщений.
        $deleted = 0;
        do {
            $ids = ErpProcessedMessage::query()
                ->where('processed_at', '<', $cutoff)
                ->orderBy('processed_at')
                ->limit($chunk)
                ->pluck('message_id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += ErpProcessedMessage::whereIn('message_id', $ids)->delete();
        } while (true);

        $this->info("Готово. Удалено {$deleted} записей старше {$days} дней.");

        Log::info('erp:cleanup-processed', [
            'days' => $days,
            'deleted' => $deleted,
        ]);

        return self::SUCCESS;
    }
}

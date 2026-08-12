<?php

namespace App\Console\Commands;

use App\Models\ProductAvailabilityEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ретенция журнала переходов доступности (crm-30).
 *
 * Заведена вместе с таблицей, а не «когда разрастётся»: проект уже терял
 * 5,8 ГБ из 6,6 ГБ боевой базы на таблицах Pulse, и их пришлось сносить
 * целиком вместе с историей.
 */
class CleanupProductAvailabilityEvents extends Command
{
    protected $signature = 'stock:cleanup-availability';

    protected $description = 'Удалить старые записи журнала доступности товаров';

    public function handle(): int
    {
        $days = max(30, (int) config('stock.availability.retention_days', 365));
        $before = Carbon::now()->subDays($days);

        $deleted = ProductAvailabilityEvent::query()
            ->where('happened_at', '<', $before)
            ->delete();

        $this->info("Удалено записей журнала доступности: {$deleted} (старше {$days} дн.).");

        return self::SUCCESS;
    }
}

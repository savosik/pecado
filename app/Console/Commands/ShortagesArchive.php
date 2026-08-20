<?php

namespace App\Console\Commands;

use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Обнуление рабочего списка недоборов: старые отмены уходят в архив.
 *
 * Нужна при запуске раздела и после долгих перерывов в разборе: разбирать
 * задним числом сотни строк никто не станет, а «красный» счётчик, который
 * невозможно обнулить, перестают замечать вместе со всеми остальными.
 *
 * Архивируются только НЕразмеченные строки: если менеджер уже поставил метку,
 * строка разнесена, и трогать её незачем. Данные не удаляются — сводки
 * по повторяющимся товарам и партнёрам продолжают их учитывать.
 */
class ShortagesArchive extends Command
{
    protected $signature = 'shortages:archive
        {--before= : Архивировать отмены строго раньше этой даты (по умолчанию — все на текущий момент)}
        {--dry-run : Показать, сколько строк уйдёт в архив, ничего не меняя}';

    protected $description = 'Отправить неразмеченные недоборы в архив журнала';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $before = $this->option('before') ? Carbon::parse((string) $this->option('before')) : null;

        $query = OrderItem::query()
            ->where('cancelled', true)
            ->whereNull('cancel_source')
            ->whereNull('cancel_archived_at');

        if ($before !== null) {
            // Строки без собственной даты отмены (история до журнала) отсекаются
            // по дате заказа — тем же правилом, по которому их показывает журнал.
            $query->whereHas('order', fn ($q) => $q
                ->whereRaw('COALESCE(order_items.cancelled_at, orders.erp_created_at, orders.created_at) < ?', [$before]));
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Нечего архивировать: неразмеченных недоборов нет.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('В архив ушло бы %d строк(и). (dry-run, ничего не записано)', $count));

            return self::SUCCESS;
        }

        $query->update(['cancel_archived_at' => now()]);

        $this->info(sprintf(
            'В архив отправлено %d строк(и)%s. В журнале они видны фильтром «Архив».',
            $count,
            $before !== null ? ' с датой раньше '.$before->format('d.m.Y') : '',
        ));

        return self::SUCCESS;
    }
}

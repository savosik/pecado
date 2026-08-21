<?php

namespace App\Console\Commands\Notifications;

use App\Services\Notifications\Pulse\DigestSender;
use Illuminate\Console\Command;

/**
 * Отправка накопленных писем одним.
 *
 * Серия правок заказа из 1С даёт десяток событий подряд; правило со сведением
 * копит их и отдаёт клиенту одним письмом вместо десяти.
 */
class SendDigests extends Command
{
    protected $signature = 'notifications:send-digests {--period=hourly : hourly или daily}';

    protected $description = 'Свести накопленные уведомления и отправить одним письмом';

    public function handle(DigestSender $sender): int
    {
        $period = $this->option('period') === 'daily' ? 'daily' : 'hourly';

        $result = $sender->send($period);

        $this->info(sprintf(
            'Сведение (%s): отправлено писем %d, свёрнуто событий %d.',
            $period,
            $result['sent'],
            $result['collapsed'],
        ));

        return self::SUCCESS;
    }
}

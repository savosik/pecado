<?php

namespace App\Console\Commands\Notifications;

use App\Models\NotificationDelivery;
use App\Models\SentEmail;
use Illuminate\Console\Command;

/**
 * Сверка теневого режима с фактической отправкой.
 *
 * Пока пульт работает в shadow, он пишет, кому бы отправил. Эта команда
 * сопоставляет расчёт с тем, что реально ушло старым механизмом. Переключать
 * событие в боевой режим можно, только когда расхождений нет либо каждое
 * объяснено и принято осознанно.
 */
class CompareWithLegacy extends Command
{
    protected $signature = 'notifications:compare {--days=7 : За сколько дней сверять}';

    protected $description = 'Сверить расчёт пульта с фактически отправленными письмами';

    public function handle(): int
    {
        $since = now()->subDays((int) $this->option('days'));

        $shadow = NotificationDelivery::query()
            ->where('created_at', '>=', $since)
            ->where('skip_reason', NotificationDelivery::REASON_SHADOW)
            ->get()
            ->map(fn (NotificationDelivery $d) => $this->key($d->client_user_id, $d->recipient))
            ->unique();

        $actual = SentEmail::query()
            ->where('sent_at', '>=', $since)
            ->whereNull('notification_delivery_id')
            ->get()
            ->map(fn (SentEmail $e) => $this->key($e->client_user_id, $e->recipient))
            ->unique();

        $onlyPulse = $shadow->diff($actual)->values();
        $onlyLegacy = $actual->diff($shadow)->values();
        $both = $shadow->intersect($actual)->count();

        $this->info("Сверка за {$this->option('days')} дн.");
        $this->line("Совпало: {$both}");

        $this->newLine();
        $this->line('<comment>Пульт бы отправил, а письма не было</comment> ('.$onlyPulse->count().')');
        $onlyPulse->take(30)->each(fn (string $row) => $this->line('  '.$row));

        $this->newLine();
        $this->line('<comment>Письмо ушло, а пульт бы не отправил</comment> ('.$onlyLegacy->count().')');
        $onlyLegacy->take(30)->each(fn (string $row) => $this->line('  '.$row));

        if ($onlyPulse->isEmpty() && $onlyLegacy->isEmpty()) {
            $this->newLine();
            $this->info('Расхождений нет — событие можно переводить в боевой режим.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Есть расхождения: разберите их до перевода событий в боевой режим.');

        return self::SUCCESS;
    }

    private function key(?int $clientId, string $recipient): string
    {
        return ($clientId ?? '—').' → '.$recipient;
    }
}

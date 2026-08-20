<?php

namespace App\Console\Commands;

use App\Notifications\Shortages\DailyShortageNoticeNotification;
use App\Services\Shortage\DailyShortageDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Вечернее письмо менеджеру: сегодняшние недоборы, которые он ещё не разнёс.
 *
 * Запускается в 17:00 — к этому часу склад закрыл дневные расходные ордера,
 * а рабочий день ещё не кончился: строку можно разнести и, если нужно,
 * позвонить клиенту сегодня же.
 *
 * Молчит осознанно: нет отмен — нет письма; менеджер разнёс всё до 17:00 —
 * письма тоже нет. Повторный запуск в тот же день дублей не рассылает.
 */
class ShortagesDailyNotice extends Command
{
    protected $signature = 'shortages:daily-notice
        {--date= : День, за который собрать сводку (по умолчанию — сегодня)}
        {--dry-run : Показать получателей и цифры, ничего не отправляя}';

    protected $description = 'Разослать менеджерам сводку неразнесённых недоборов за день';

    public function handle(DailyShortageDigest $digest): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! config('notifications.mail.features.shortage_daily_notice') && ! $dryRun) {
            $this->warn('Сводка недоборов выключена (MAIL_FEATURE_SHORTAGE_NOTICE=false) — письма не уходят.');

            return self::SUCCESS;
        }

        $day = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $groups = $digest->forDay($day);

        if ($groups === []) {
            $this->info(sprintf('Неразнесённых недоборов за %s нет — писем не будет.', $day->format('d.m.Y')));

            return self::SUCCESS;
        }

        // Повторный запуск (ручной прогон, задвоенный планировщик) в тот же день
        // не рассылает дубли. --date пересобирает прошлое без отметки.
        $guardKey = 'shortages-daily-notice:'.$day->toDateString();

        if (! $dryRun && ! $this->option('date') && ! Cache::add($guardKey, now()->toDateTimeString(), now()->addHours(20))) {
            $this->warn('Сводка за этот день уже рассылалась — повторно не шлём.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($groups as $group) {
            /** @var \App\Models\User $recipient */
            $recipient = $group['recipient'];
            /** @var \Illuminate\Support\Collection<int, \App\Models\OrderItem> $items */
            $items = $group['items'];

            $this->line(sprintf(
                '%s — %d строк(и), %d шт., %s ₽%s',
                $recipient->email,
                $group['lines_count'],
                $group['quantity'],
                number_format($group['amount'], 0, ',', ' '),
                $group['on_behalf_of'] !== null ? ' (замещает '.$group['on_behalf_of']->name.')' : '',
            ));

            if ($dryRun) {
                continue;
            }

            $recipient->notify(new DailyShortageNoticeNotification(
                items: $items,
                amount: (float) $group['amount'],
                quantity: (int) $group['quantity'],
                ordersCount: (int) $group['orders_count'],
                dayLabel: $day->format('d.m.Y'),
                journalUrl: $this->journalUrl($day),
                onBehalfOf: $group['on_behalf_of'],
            ));

            $sent++;
        }

        $this->info($dryRun
            ? sprintf('Ушло бы %d писем. (dry-run)', count($groups))
            : sprintf('Отправлено писем: %d.', $sent));

        return self::SUCCESS;
    }

    /**
     * Ссылка сразу в нужный отбор: день сводки, только неразмеченные строки.
     */
    private function journalUrl(Carbon $day): string
    {
        return url(route('crm.shortages.index', [
            'from' => $day->format('Y-m-d'),
            'to' => $day->format('Y-m-d'),
            'source' => 'none',
        ], false));
    }
}

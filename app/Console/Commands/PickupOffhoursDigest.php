<?php

namespace App\Console\Commands;

use App\Notifications\Pickup\OffhoursDigestNotification;
use App\Services\Notifications\StaffNotifications;
use App\Services\Payroll\Support\WorkingCalendar;
use App\Services\Pickup\OffhoursDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Утренняя сводка менеджеру: самовывоз и резервы его клиентов за вечер и выходные (pick-13).
 *
 * Уходит в 9:00 рабочего дня офиса. Молчит, если рассказывать не о чем. Повторный запуск
 * в тот же день дублей не рассылает.
 */
class PickupOffhoursDigest extends Command
{
    protected $signature = 'pickup:offhours-digest {--dry-run : Показать получателей и цифры, ничего не отправляя}';

    protected $description = 'Разослать менеджерам сводку «пока вас не было»: самовывоз и резервы клиентов';

    public function handle(OffhoursDigest $digest, WorkingCalendar $calendar, StaffNotifications $staff): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! config('pickup.enabled') && ! $dryRun) {
            $this->warn('Самовывоз выключен (PICKUP_ENABLED=false) — сводка не уходит.');

            return self::SUCCESS;
        }

        if (! $calendar->isWorkingDay(now()) && ! $dryRun) {
            $this->info('Сегодня нерабочий день офиса — сводка уйдёт в ближайший рабочий.');

            return self::SUCCESS;
        }

        [$from, $to] = $digest->window(now());
        $groups = $digest->build(now());

        if ($groups === []) {
            $this->info('Событий за окно нет — писем не будет.');

            return self::SUCCESS;
        }

        if (! $dryRun && ! Cache::add('pickup-offhours-digest:'.now()->toDateString(), 1, now()->addHours(20))) {
            $this->warn('Сводка сегодня уже рассылалась — повторно не шлём.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($groups as $group) {
            $this->line(sprintf('%s — событий: %d%s', $group['recipient']->email, $group['total'],
                $group['on_behalf_of'] ? ' (замещает '.$group['on_behalf_of']->name.')' : ''));

            if ($dryRun || ! $staff->wants($group['recipient'], 'staff.pickup_offhours_digest')) {
                continue;
            }

            $group['recipient']->notify(new OffhoursDigestNotification(
                sections: $group['sections'],
                total: $group['total'],
                periodLabel: 'с '.$from->format('d.m H:i').' по '.$to->format('d.m H:i'),
                onBehalfOf: $group['on_behalf_of']?->name,
            ));
            $sent++;
        }

        $this->info($dryRun ? sprintf('Ушло бы %d писем. (dry-run)', count($groups)) : "Отправлено писем: {$sent}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Crm\TaskWeeklyReportNotification;
use App\Services\Crm\TaskWeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Пятничный отчёт по задачам: менеджерам — своя неделя, РОПу — сводка отдела.
 *
 * Неделя = понедельник 00:00 — момент запуска (пятница 17:00), в зоне
 * приложения. Повторный запуск в ту же неделю дублей не шлёт — отметка в кэше.
 */
class CrmTasksWeeklyReport extends Command
{
    protected $signature = 'crm:tasks-weekly-report
        {--date= : Собрать отчёт, как если бы команду запустили в этот момент (для пересборки прошлых недель)}
        {--dry-run : Показать получателей и цифры, ничего не отправляя}';

    protected $description = 'Разослать недельный отчёт по задачам менеджерам и РОПу';

    public function handle(TaskWeeklyReportService $reports): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! config('notifications.mail.features.crm_tasks') && ! $dryRun) {
            $this->warn('Уведомления по задачам выключены (MAIL_FEATURE_CRM_TASKS=false) — отчёта не будет.');

            return self::SUCCESS;
        }

        $moment = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'))
            : CarbonImmutable::now();

        $from = $moment->startOfWeek();
        $to = $moment;
        $periodLabel = $from->format('d.m').' — '.$to->format('d.m');

        // Повторный запуск (ручной прогон, задвоенный планировщик) в ту же
        // неделю не рассылает дубли. --date пересобирает прошлое без отметки.
        $guardKey = 'crm-tasks-weekly-report:'.$from->toDateString();

        if (! $dryRun && ! $this->option('date') && ! Cache::add($guardKey, now()->toDateTimeString(), now()->addDays(6))) {
            $this->warn('Отчёт за эту неделю уже рассылался — повторно не шлём.');

            return self::SUCCESS;
        }

        $managerIds = $reports->activeManagerIds($from, $to);
        $sent = 0;
        $rows = [];

        foreach (User::query()->whereKey($managerIds)->orderBy('name')->get() as $manager) {
            if (! $manager->can('crm-tasks.view')) {
                continue;
            }

            $stats = $reports->managerStats($manager, $from, $to);

            if (! $reports->isWorthSending($stats)) {
                continue;
            }

            $rows[] = ['name' => (string) $manager->name, 'stats' => $stats];

            $this->line(sprintf(
                '  %s: закрыто %d (успешно %d / проблема %d), переносов %d, просрочено %d',
                $manager->name,
                $stats['closed_total'],
                $stats['closed_success'],
                $stats['closed_problem'],
                $stats['postpones'],
                $stats['overdue_now'],
            ));

            if (! $dryRun) {
                $manager->notify(new TaskWeeklyReportNotification($stats, null, $periodLabel));
            }

            $sent++;
        }

        // РОПам — сводка по отделу (личное письмо они получили выше, если
        // у них были свои задачи).
        if ($rows !== []) {
            $heads = User::query()
                ->whereHas('roles')
                ->get()
                ->filter(fn (User $user): bool => $user->hasCrmAccess()
                    && $user->can('crm-department.view')
                    && $user->can('crm-tasks.view'));

            foreach ($heads as $head) {
                $this->line("  РОП: {$head->name} — сводка по ".count($rows).' менеджерам');

                if (! $dryRun) {
                    $head->notify(new TaskWeeklyReportNotification(
                        $reports->managerStats($head, $from, $to),
                        $rows,
                        $periodLabel,
                    ));
                }

                $sent++;
            }
        }

        $this->info("Отчётов: {$sent}".($dryRun ? ' (сухой прогон)' : ''));

        return self::SUCCESS;
    }
}

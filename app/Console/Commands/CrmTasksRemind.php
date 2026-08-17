<?php

namespace App\Console\Commands;

use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Notifications\Crm\TaskDueSoonNotification;
use Illuminate\Console\Command;

/**
 * Напоминания о сроках задач: за день до дедлайна и в день просрочки.
 *
 * Просрочку берём окном в сутки, а не «всё, что просрочено»: иначе забытая задача
 * писала бы исполнителю каждое утро до скончания века, и напоминания перестали бы
 * читать вовсе.
 *
 * Дедупликация — через общий reminder-лог (канал mail): двойной прогон команды
 * или задвоенный воркер не отправят письмо повторно, а перенос срока стирает
 * отметку — к новому сроку письмо уйдёт снова.
 */
class CrmTasksRemind extends Command
{
    protected $signature = 'crm:tasks-remind
        {--dry-run : Только показать, кому ушли бы письма, ничего не отправляя}';

    protected $description = 'Напомнить исполнителям о завтрашних дедлайнах и о просроченных за сутки задачах';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! config('notifications.mail.features.crm_tasks') && ! $dryRun) {
            $this->warn('Уведомления по задачам выключены (MAIL_FEATURE_CRM_TASKS=false) — писем не будет.');

            return self::SUCCESS;
        }

        $soon = $this->notify(
            CrmTask::query()
                ->whereIn('status', TaskStatus::activeValues())
                ->whereBetween('due_at', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()]),
            overdue: false,
            dryRun: $dryRun,
        );

        $overdue = $this->notify(
            CrmTask::query()
                ->whereIn('status', TaskStatus::activeValues())
                ->whereBetween('due_at', [now()->subDay(), now()]),
            overdue: true,
            dryRun: $dryRun,
        );

        $this->info("Напоминаний о завтрашнем сроке: {$soon}, о просрочке: {$overdue}".($dryRun ? ' (сухой прогон)' : ''));

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CrmTask>  $query
     */
    private function notify(\Illuminate\Database\Eloquent\Builder $query, bool $overdue, bool $dryRun): int
    {
        $sent = 0;
        $reminders = app(\App\Services\Crm\TaskReminderService::class);
        $kind = $overdue
            ? \App\Models\CrmTaskReminderLog::KIND_OVERDUE
            : \App\Models\CrmTaskReminderLog::KIND_DUE_SOON;

        $query->with(['assignee', 'author:id,name'])->chunkById(200, function ($tasks) use ($overdue, $dryRun, &$sent, $reminders, $kind) {
            foreach ($tasks as $task) {
                if ($dryRun) {
                    $sent++;
                    $this->line("  #{$task->id} {$task->title} → {$task->assignee->name}");

                    continue;
                }

                // Отметка в общем логе: не удалось — уже слали, молча пропускаем.
                if (! $reminders->claim($task, $task->assignee, $kind, \App\Models\CrmTaskReminderLog::CHANNEL_MAIL)) {
                    continue;
                }

                $sent++;
                $this->line("  #{$task->id} {$task->title} → {$task->assignee->name}");
                $task->assignee->notify(new TaskDueSoonNotification($task, $overdue));
            }
        });

        return $sent;
    }
}

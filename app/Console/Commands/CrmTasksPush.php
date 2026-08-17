<?php

namespace App\Console\Commands;

use App\Models\CrmTaskReminderLog;
use App\Models\User;
use App\Notifications\Crm\TaskPushNotification;
use App\Services\Crm\TaskReminderService;
use Illuminate\Console\Command;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Push-напоминания по задачам: тем, у кого есть подписанные браузеры.
 *
 * Ходит раз в 10 минут — push и должен приходить около момента срока, а не
 * утренним дайджестом. Поводы и дедупликация — общий reminder-контур
 * (канал push независим от тостов и писем, но повод один).
 */
class CrmTasksPush extends Command
{
    protected $signature = 'crm:tasks-push {--dry-run : Показать, кому ушли бы push, ничего не отправляя}';

    protected $description = 'Отправить push-напоминания о задачах подписанным браузерам';

    public function handle(TaskReminderService $reminders): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! config('crm.push.enabled') && ! $dryRun) {
            $this->warn('Push выключен (CRM_PUSH_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! config('webpush.vapid.public_key') && ! $dryRun) {
            $this->warn('VAPID-ключи не настроены — push отправлять нечем.');

            return self::SUCCESS;
        }

        // Только пользователи с живыми подписками: остальных и проверять незачем.
        $userIds = PushSubscription::query()
            ->where('subscribable_type', User::class)
            ->distinct()
            ->pluck('subscribable_id');

        $sent = 0;

        foreach (User::query()->whereKey($userIds)->get() as $user) {
            if (! $user->can('crm-tasks.view')) {
                continue;
            }

            foreach ($reminders->pendingPushFor($user) as [$task, $kind, $title]) {
                if ($dryRun) {
                    $sent++;
                    $this->line("  #{$task->id} {$task->title} → {$user->name} ({$kind})");

                    continue;
                }

                if (! $reminders->claim($task, $user, $kind, CrmTaskReminderLog::CHANNEL_PUSH)) {
                    continue;
                }

                $sent++;
                $this->line("  #{$task->id} {$task->title} → {$user->name} ({$kind})");
                $user->notify(new TaskPushNotification($task, $title));
            }
        }

        $this->info("Push-напоминаний: {$sent}".($dryRun ? ' (сухой прогон)' : ''));

        return self::SUCCESS;
    }
}

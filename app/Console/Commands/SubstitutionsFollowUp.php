<?php

namespace App\Console\Commands;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Enums\Substitution\OfferStatus;
use App\Models\CrmTask;
use App\Models\SubstitutionOffer;
use App\Notifications\Substitutions\SubstitutionReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Дожим подборок замен — три правила, все отменяются реакцией клиента:
 *
 * 1. Отправлено, сутки не открыто → одно вежливое напоминание клиенту.
 * 2. Открыто, двое суток не согласовано → задача менеджеру «позвонить».
 * 3. Срок истёк → подборка expired + финальная задача: продлить или закрыть.
 *
 * Открытие/согласование между тиками отменяет шаги сами: условия выборок
 * их больше не находят. Повторную отправку блокируют отметки на подборке
 * (reminded_at / call_task_at), а не память команды.
 */
class SubstitutionsFollowUp extends Command
{
    protected $signature = 'substitutions:follow-up
        {--dry-run : Показать, что произошло бы, ничего не отправляя и не меняя}';

    protected $description = 'Дожим подборок замен: напоминание клиенту, задача «позвонить», просрочка';

    public function handle(): int
    {
        if (! config('substitutions.enabled')) {
            $this->warn('Контур замен выключен (SHORTAGE_OFFERS_ENABLED=false) — дожим не выполняется.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $reminded = $this->remindUnopened($dryRun);
        $callTasks = $this->scheduleCalls($dryRun);
        $expired = $this->expire($dryRun);

        $this->info(sprintf(
            'Напоминаний: %d, задач «позвонить»: %d, просрочено: %d.%s',
            $reminded,
            $callTasks,
            $expired,
            $dryRun ? ' (dry-run, ничего не записано)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Отправлено, сутки не открыто → одно напоминание.
     */
    private function remindUnopened(bool $dryRun): int
    {
        // Напоминание уходит клиенту без действия менеджера, поэтому у него
        // отдельный флаг: reminded_at не проставляется, пока канал выключен.
        if (! config('substitutions.client_auto_enabled')) {
            return 0;
        }

        $threshold = now()->subHours((int) config('substitutions.follow_up.remind_after_hours', 24));

        $offers = SubstitutionOffer::query()
            ->open()
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $threshold)
            ->whereNull('viewed_at')
            ->whereNull('reminded_at')
            ->where('expires_at', '>', now())
            ->with(['order', 'user', 'manager'])
            ->get();

        foreach ($offers as $offer) {
            $email = $offer->user?->email;

            if (blank($email)) {
                continue;
            }

            if (! $dryRun) {
                Notification::route('mail', $email)->notify(new SubstitutionReminderNotification($offer));
                $offer->update(['reminded_at' => now()]);
            }
        }

        return $offers->count();
    }

    /**
     * Открыто, двое суток тишины → задача менеджеру «позвонить».
     */
    private function scheduleCalls(bool $dryRun): int
    {
        // call_task_at при выключенных задачах не отмечается: после включения
        // всё ещё актуальные подборки (VIEWED, не истёкшие) задачу получат.
        if (! config('substitutions.manager_tasks_enabled')) {
            return 0;
        }

        $threshold = now()->subHours((int) config('substitutions.follow_up.call_task_after_hours', 48));

        $offers = SubstitutionOffer::query()
            ->where('status', OfferStatus::VIEWED)
            ->whereNotNull('viewed_at')
            ->where('viewed_at', '<=', $threshold)
            ->whereNull('call_task_at')
            ->where('expires_at', '>', now())
            ->with(['order', 'user', 'manager'])
            ->get();

        foreach ($offers as $offer) {
            if (! $dryRun) {
                $this->createTask(
                    $offer,
                    sprintf(
                        'Позвонить по недобору: заказ %s — %s открыл подборку, но молчит',
                        $offer->order?->erp_number ?: $offer->order?->number,
                        $offer->user?->name ?? 'клиент',
                    ),
                    TaskPriority::NORMAL,
                );

                $offer->update(['call_task_at' => now()]);
            }
        }

        return $offers->count();
    }

    /**
     * Срок истёк → expired + финальная задача менеджеру.
     */
    private function expire(bool $dryRun): int
    {
        $offers = SubstitutionOffer::query()
            ->open()
            ->where('expires_at', '<=', now())
            ->with(['order', 'user', 'manager'])
            ->get();

        foreach ($offers as $offer) {
            if (! $dryRun) {
                $offer->update(['status' => OfferStatus::EXPIRED]);

                $this->createTask(
                    $offer,
                    sprintf(
                        'Подборка замен по заказу %s просрочена — продлить или закрыть',
                        $offer->order?->erp_number ?: $offer->order?->number,
                    ),
                    TaskPriority::NORMAL,
                );
            }
        }

        return $offers->count();
    }

    private function createTask(SubstitutionOffer $offer, string $title, TaskPriority $priority): void
    {
        // Просрочка офферу проставляется в любом случае, задача — только
        // при включённых задачах менеджеру.
        if (! config('substitutions.manager_tasks_enabled')) {
            return;
        }

        $assignee = $offer->manager ?? \App\Models\User::role('sales-head')->orderBy('id')->first();

        if ($assignee === null) {
            return;
        }

        $task = new CrmTask([
            'title' => $title,
            'description' => 'Карточка недобора: '.url('/crm/shortages/'.$offer->id),
            'author_id' => $assignee->id,
            'assignee_id' => $assignee->id,
            'status' => TaskStatus::OPEN,
            'priority' => $priority,
            'due_at' => now()->endOfDay(),
        ]);

        if ($offer->order !== null) {
            $task->related()->associate($offer->order);
        }

        $task->save();
    }
}

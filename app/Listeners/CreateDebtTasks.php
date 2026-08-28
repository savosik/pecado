<?php

namespace App\Listeners;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Enums\DebtLevel;
use App\Events\DebtLevelChanged;
use App\Events\DebtPauseExpired;
use App\Models\CrmTask;
use App\Models\User;
use App\Services\Crm\CrmTaskService;
use App\Services\Crm\ManagerAbsenceResolver;
use App\Support\Debt\DebtControl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Автозадачи лестницы долга (карточка debt-05).
 *
 * | Переход              | Кому                | Задача                                   |
 * |----------------------|---------------------|------------------------------------------|
 * | → no_orders          | менеджер партнёра   | «Позвонить: заказы контрагента закрыты»  |
 * | → hold               | менеджер партнёра   | «Подготовить и отправить досудебку»      |
 * | разблокировка истекла| кто ставил          | «Разблокировка истекла»                  |
 *
 * Одна живая задача на (клиент, повод): повторный пересчёт дублей не плодит.
 * Задача уходит замещающему, если менеджер в отпуске.
 */
class CreateDebtTasks
{
    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly ManagerAbsenceResolver $absences,
    ) {}

    public function handle(DebtLevelChanged|DebtPauseExpired $event): void
    {
        if (! DebtControl::live(DebtControl::ACTION_TASKS)) {
            return;
        }

        if ($event instanceof DebtPauseExpired) {
            $this->pauseExpired($event);

            return;
        }

        if (! $event->isEscalation()) {
            return;
        }

        match ($event->to) {
            DebtLevel::NO_ORDERS => $this->callTask($event),
            DebtLevel::HOLD => $this->pretrialTask($event),
            default => null,
        };
    }

    private function callTask(DebtLevelChanged $event): void
    {
        $state = $event->state;
        $client = $state->user;
        $company = $state->company;
        $assignee = $this->managerOf($client);

        if ($client === null || $assignee === null) {
            return;
        }

        $title = sprintf('Позвонить: заказы «%s» закрыты за просрочку', $company?->name ?? $client->display_name);

        $this->createOnce($assignee, $company ?? $client, [
            'title' => $title,
            'description' => sprintf(
                "Ступень «%s»: просрочка %s ₽, самый ранний срок %s (%d дн.).\nОграничение снимется автоматически в день оплаты. Если клиент обещает заплатить к дате — поставьте разблокировку до этой даты в карточке партнёра.",
                $state->level->label(),
                number_format((float) $state->overdue_amount, 2, ',', ' '),
                $state->oldest_due_date?->format('d.m.Y') ?? '—',
                $state->age_days,
            ),
            'priority' => TaskPriority::HIGH->value,
            'due_at' => now()->addDay()->setTime(11, 0)->toDateTimeString(),
        ]);
    }

    private function pretrialTask(DebtLevelChanged $event): void
    {
        $state = $event->state;
        $client = $state->user;
        $company = $state->company;
        $assignee = $this->managerOf($client);

        if ($client === null || $assignee === null) {
            return;
        }

        $title = sprintf('Досудебная претензия: «%s» — стоп-отгрузка', $company?->name ?? $client->display_name);

        $this->createOnce($assignee, $company ?? $client, [
            'title' => $title,
            'description' => sprintf(
                "Просрочка %s ₽ не гасится %d дн. (срок %s) и составляет почти весь долг партнёра.\nПодготовить претензию: акт сверки и печатные формы — в разделах «Акт сверки» и «Документы». Отправка — только вручную, после проверки.",
                number_format((float) $state->overdue_amount, 2, ',', ' '),
                $state->age_days,
                $state->oldest_due_date?->format('d.m.Y') ?? '—',
            ),
            'priority' => TaskPriority::HIGH->value,
            'due_at' => now()->addDays(3)->setTime(11, 0)->toDateTimeString(),
        ]);
    }

    private function pauseExpired(DebtPauseExpired $event): void
    {
        $pause = $event->pause;
        $author = $pause->author;
        $client = $pause->user;

        if ($author === null || $client === null) {
            return;
        }

        $this->createOnce($author, $client, [
            'title' => sprintf('Разблокировка истекла: %s', $client->display_name),
            'description' => sprintf(
                "Разблокировка до %s (%s) истекла, оплата не поступила — ограничения вернулись.\nСвязаться с клиентом; при новой договорённости — поставить разблокировку заново.",
                $pause->until->format('d.m.Y'),
                $pause->reason,
            ),
            'priority' => TaskPriority::HIGH->value,
            'due_at' => now()->setTime(11, 0)->toDateTimeString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOnce(User $assignee, Model $related, array $data): void
    {
        $exists = CrmTask::query()
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', $related->getKey())
            ->where('title', $data['title'])
            ->whereIn('status', [TaskStatus::OPEN->value, TaskStatus::IN_PROGRESS->value])
            ->exists();

        if ($exists) {
            return;
        }

        try {
            $this->tasks->create($assignee, ['assignee_id' => $assignee->getKey()] + $data, $related);
        } catch (\Throwable $exception) {
            Log::error('Лестница долга: задача не создана', [
                'title' => $data['title'],
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Аккаунт менеджера партнёра с учётом замещения.
     */
    private function managerOf(?User $client): ?User
    {
        $card = $client?->personalManager;

        if ($card === null) {
            return null;
        }

        return $this->absences->effectiveManager($card)->user ?? $card->user;
    }
}

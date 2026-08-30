<?php

namespace App\Services\Crm;

use App\Enums\Crm\CrmScope;
use App\Enums\Crm\TaskOutcome;
use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\User;
use App\Notifications\Crm\TaskAssignedNotification;
use App\Notifications\Crm\WatchedTaskEventNotification;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Работа с задачами менеджеров: скоуп видимости, создание, правка, форма ответа.
 *
 * Единая точка на все пути: раздел «Задачи», врезка в карточке сущности и (позже)
 * ИИ-агент менеджера. Иначе назначение исполнителя, уведомление и проверка доступа
 * повторялись бы в каждом контроллере — и однажды разошлись бы.
 */
class CrmTaskService
{
    /**
     * Задачи, доступные актору.
     *
     * Кто не видит отдел — только те, в которых участвует. Ровно та же граница,
     * что в `CrmTaskPolicy::view()`: список и политика обязаны говорить одно
     * и то же, иначе задача из списка открывалась бы в 403.
     *
     * Разрез «только мои» сужает до участия и того, кто отдел видит: на экране
     * задач «мои» — это про участие, а не про закреплённых партнёров. Так это
     * слово понимает менеджер, когда спрашивает «что на мне».
     *
     * @return Builder<CrmTask>
     */
    public function visibleTo(User $actor, CrmScope $scope = CrmScope::DEPARTMENT): Builder
    {
        $query = CrmTask::query();

        if ($scope->isMine() || ! $actor->can('crm-department.view')) {
            $actorId = (int) $actor->getKey();

            $query->where(fn (Builder $inner) => $inner
                ->where('author_id', $actorId)
                ->orWhere('assignee_id', $actorId)
                ->orWhereHas('coAssignees', fn (Builder $users) => $users->whereKey($actorId))
                ->orWhereHas('watchers', fn (Builder $users) => $users->whereKey($actorId)));
        }

        return $query;
    }

    /**
     * Партнёры актора, по которым не поставлено ни одной незакрытой задачи.
     *
     * Проактивность продаж измеряется не количеством закрытых задач, а тем, что
     * по каждому партнёру известен следующий шаг. Партнёр без активной задачи — это
     * партнёр, о котором вспомнят только когда он сам позвонит.
     *
     * @return Builder<User>
     */
    public function uncoveredClients(User $actor): Builder
    {
        return User::query()
            ->visibleInCrm($actor)
            ->whereDoesntHave('crmTasks', fn (Builder $tasks) => $tasks
                ->whereIn('status', TaskStatus::activeValues()));
    }

    /**
     * Подсчёт незакрытых задач партнёра — для колонки «Задачи» в списке.
     *
     * @return array<string, \Closure>
     */
    public function activeTasksCount(): array
    {
        return [
            'crmTasks as active_tasks_count' => fn (Builder $tasks) => $tasks
                ->whereIn('status', TaskStatus::activeValues()),
        ];
    }

    /**
     * Кому можно поручить задачу.
     *
     * Только сотрудники с доступом в CRM: задача, поставленная кладовщику или партнёру,
     * не появилась бы ни в одном интерфейсе и просто потерялась бы.
     *
     * @return list<array{id: int, name: string}>
     */
    public function assignableUsers(): array
    {
        // Кандидаты — только сотрудники (у кого есть роль): CRM-доступ бывает и через
        // роль, и через прямое право, поэтому окончательная проверка идёт в PHP.
        // Перебирать так всю таблицу users нельзя — партнёров там тысячи, сотрудников десятки.
        return User::query()
            ->select('id', 'name')
            ->whereHas('roles')
            ->with(['roles.permissions:id,name', 'permissions:id,name'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->hasCrmAccess())
            ->map(fn (User $user): array => [
                'id' => (int) $user->getKey(),
                'name' => (string) $user->name,
            ])
            ->values()
            ->all();
    }

    public function canBeAssigned(int $userId): bool
    {
        $user = User::query()->find($userId);

        return $user !== null && $user->hasCrmAccess();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?Model $related): CrmTask
    {
        $task = new CrmTask([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignee_id' => (int) ($data['assignee_id'] ?? $actor->getKey()),
            'status' => $data['status'] ?? TaskStatus::OPEN->value,
            'priority' => $data['priority'] ?? TaskPriority::NORMAL->value,
            'due_at' => $this->parseDue($data['due_at'] ?? null),
            'estimate_minutes' => $data['estimate_minutes'] ?? null,
        ]);

        if ($related !== null) {
            $task->related()->associate($related);
        }

        $task->author_id = (int) $actor->getKey();
        // client_user_id и done_at проставляет сама модель — единая точка на все пути.
        $task->save();

        $added = $this->syncCoAssignees($task, $data['co_assignee_ids'] ?? null);

        // Чек-лист прямо при постановке: «обзвонить пятерых» заводится одной формой.
        foreach (array_values($data['checklist'] ?? []) as $index => $itemTitle) {
            $title = trim((string) $itemTitle);

            if ($title !== '') {
                $task->checklistItems()->create(['title' => $title, 'position' => $index + 1]);
            }
        }

        $this->syncTags($task, $data['tags'] ?? null);

        $this->notifyAssignee($task, $actor);
        $this->notifyCoAssignees($task, $actor, $added);

        return $task;
    }

    /**
     * Теги задачи — общий справочник spatie с собственным типом.
     *
     * @param  list<string>|null  $tags  null — не трогаем
     */
    private function syncTags(CrmTask $task, ?array $tags): void
    {
        if ($tags === null) {
            return;
        }

        $clean = collect($tags)
            ->map(fn ($tag): string => trim((string) $tag))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $task->syncTagsWithType($clean, CrmTask::TAG_TYPE);
        $task->unsetRelation('tags');
    }

    /**
     * Состав соисполнителей: ответственный в pivot не дублируется, дубли схлопываются.
     *
     * @param  list<int|string>|null  $ids  null — состав не трогаем
     * @return list<int> добавленные пользователи — им уходит уведомление
     */
    private function syncCoAssignees(CrmTask $task, ?array $ids): array
    {
        if ($ids === null) {
            return [];
        }

        $clean = collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $id === (int) $task->assignee_id)
            ->values()
            ->all();

        $result = $task->coAssignees()->sync($clean);
        $task->unsetRelation('coAssignees');

        return array_map('intval', $result['attached'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CrmTask $task, array $data, User $actor): CrmTask
    {
        $previousAssignee = (int) $task->assignee_id;

        foreach (['title', 'description', 'status', 'priority'] as $field) {
            if (array_key_exists($field, $data)) {
                $task->{$field} = $data[$field];
            }
        }

        if (array_key_exists('due_at', $data)) {
            $task->due_at = $this->parseDue($data['due_at']);
        }

        if (array_key_exists('estimate_minutes', $data)) {
            $task->estimate_minutes = $data['estimate_minutes'] === null || $data['estimate_minutes'] === ''
                ? null
                : (int) $data['estimate_minutes'];
        }

        // Переназначение — отдельное право: исполнитель может закрыть задачу,
        // но не перевесить её на третьего. Состав соисполнителей — то же право:
        // это тоже перераспределение работы.
        $added = [];

        if ($actor->can('reassign', $task)) {
            if (array_key_exists('assignee_id', $data)) {
                $task->assignee_id = (int) $data['assignee_id'];
            }

            if (array_key_exists('co_assignee_ids', $data)) {
                $added = $this->syncCoAssignees($task, $data['co_assignee_ids']);
            }
        }

        $task->save();

        if (array_key_exists('tags', $data)) {
            $this->syncTags($task, $data['tags'] ?? []);
        }

        if ((int) $task->assignee_id !== $previousAssignee) {
            $this->notifyAssignee($task, $actor);
        }

        $this->notifyCoAssignees($task, $actor, $added);

        return $task;
    }

    /**
     * Личный контроль: поставить задачу на контроль пользователю.
     */
    public function watch(CrmTask $task, User $watcher): void
    {
        $task->watchers()->syncWithoutDetaching([(int) $watcher->getKey()]);
        $task->unsetRelation('watchers');
    }

    public function unwatch(CrmTask $task, User $watcher): void
    {
        $task->watchers()->detach((int) $watcher->getKey());
        $task->unsetRelation('watchers');
    }

    /**
     * Закрытие задачи: отметка о выполнении, комментарий и следующий шаг.
     *
     * Всё тремя записями в одной транзакции: закрытие с потерянным комментарием
     * или с несозданной следующей задачей — это ровно тот разрыв, из-за которого
     * работа с партнёром и рассыпается на разовые дёрганья.
     *
     * Комментарий и follow-up необязательны: заставлять писать отчёт по каждой
     * мелочи — верный способ научить людей не закрывать задачи вовсе.
     *
     * @param  array<string, mixed>|null  $followUp
     * @return array{task: CrmTask, follow_up: CrmTask|null}
     */
    public function close(
        CrmTask $task,
        User $actor,
        ?string $comment = null,
        ?array $followUp = null,
        ?TaskOutcome $outcome = null,
    ): array {
        return DB::transaction(function () use ($task, $actor, $comment, $followUp, $outcome): array {
            $task->status = TaskStatus::DONE;
            // Старые вызовы (кнопка-галочка, агент без параметра) закрывают
            // «успешно»: это ожидаемый смысл галочки, а не отсутствие исхода.
            $task->outcome = $outcome ?? TaskOutcome::SUCCESS;
            $task->save();

            $this->notifyWatchersAboutClose($task, $actor);

            $comment = $comment === null ? null : trim($comment);

            if ($comment !== null && $comment !== '') {
                $record = new CrmComment(['body' => $comment]);
                $record->commentable()->associate($task);
                $record->user_id = (int) $actor->getKey();
                $record->save();
            }

            $next = null;

            if ($followUp !== null && ($followUp['title'] ?? '') !== '') {
                $next = $this->create(
                    $actor,
                    [
                        'title' => $followUp['title'],
                        'description' => $followUp['description'] ?? null,
                        // По умолчанию следующий шаг остаётся за тем же человеком:
                        // чаще всего это продолжение своей же работы с партнёром.
                        'assignee_id' => $followUp['assignee_id'] ?? $task->assignee_id,
                        'priority' => $followUp['priority'] ?? $task->priority->value,
                        'due_at' => $followUp['due_at'] ?? null,
                    ],
                    // Привязка наследуется: следующий шаг по партнёру — это шаг
                    // по тому же партнёру, и заново искать его незачем.
                    $task->related,
                );

                $next->follow_up_of_id = (int) $task->getKey();
                $next->save();
            }

            return ['task' => $task, 'follow_up' => $next];
        });
    }

    /**
     * Перенос срока — не закрытие: задача остаётся в своём статусе.
     *
     * Сдвиг даты, счётчик и системный комментарий ложатся одной транзакцией:
     * перенос без следа в ленте выглядел бы как «срок сам собой поменялся»,
     * а счётчик без комментария не отвечал бы на вопрос «почему».
     */
    public function postpone(CrmTask $task, User $actor, Carbon $newDue, ?string $reason = null): CrmTask
    {
        return DB::transaction(function () use ($task, $actor, $newDue, $reason): CrmTask {
            $previousLabel = $task->due_at?->format('d.m.Y H:i') ?? 'без срока';

            $task->due_at = $newDue;
            $task->postponed_count = (int) $task->postponed_count + 1;
            $task->save();

            $body = sprintf(
                'Перенесена с «%s» на «%s»%s',
                $previousLabel,
                $newDue->format('d.m.Y H:i'),
                $reason !== null && trim($reason) !== '' ? ': '.trim($reason) : '',
            );

            $record = new CrmComment(['body' => $body]);
            $record->commentable()->associate($task);
            $record->user_id = (int) $actor->getKey();
            $record->save();

            // К новому сроку напоминания срабатывают заново — во всех каналах.
            app(TaskReminderService::class)->resetDueReminders($task);

            $this->notifyWatchers($task, $actor, 'postponed', $reason);

            return $task;
        });
    }

    /**
     * Контролёрам — о закрытии задачи, которую они наблюдают.
     */
    private function notifyWatchersAboutClose(CrmTask $task, User $actor): void
    {
        $this->notifyWatchers($task, $actor, 'closed');
    }

    /**
     * @param  'closed'|'postponed'  $event
     */
    private function notifyWatchers(CrmTask $task, User $actor, string $event, ?string $detail = null): void
    {
        if (! config('notifications.mail.features.crm_tasks')) {
            return;
        }

        $watchers = $task->watchers()
            ->whereKeyNot((int) $actor->getKey())
            ->get();

        foreach ($watchers as $watcher) {
            if (! app(\App\Services\Notifications\StaffNotifications::class)->wants($watcher, 'staff.task_watched')) {
                continue;
            }

            $watcher->notify(new WatchedTaskEventNotification($task, $event, $detail));
        }
    }

    /**
     * Уведомление исполнителю — за фича-флагом, как все письма проекта.
     *
     * Себе задачу ставят чаще, чем коллеге, и письмо о собственном поручении —
     * это спам, поэтому автору-исполнителю не пишем.
     */
    private function notifyAssignee(CrmTask $task, User $actor): void
    {
        if (! config('notifications.mail.features.crm_tasks')) {
            return;
        }

        if ((int) $task->assignee_id === (int) $actor->getKey()) {
            return;
        }

        $task->loadMissing('assignee');
        if (app(\App\Services\Notifications\StaffNotifications::class)->wants($task->assignee, 'staff.task_assigned')) {
            $task->assignee->notify(new TaskAssignedNotification($task));
        }
    }

    /**
     * Уведомление новым соисполнителям — только добавленным, не всему составу
     * при каждой правке, и не тому, кто добавил сам себя.
     *
     * @param  list<int>  $addedIds
     */
    private function notifyCoAssignees(CrmTask $task, User $actor, array $addedIds): void
    {
        if ($addedIds === [] || ! config('notifications.mail.features.crm_tasks')) {
            return;
        }

        $recipients = User::query()
            ->whereKey(array_diff($addedIds, [(int) $actor->getKey()]))
            ->get();

        foreach ($recipients as $recipient) {
            if (! app(\App\Services\Notifications\StaffNotifications::class)->wants($recipient, 'staff.task_assigned')) {
                continue;
            }

            $recipient->notify(new TaskAssignedNotification($task));
        }
    }

    /**
     * Дедлайн из формы: пустая строка — это «без срока», а не «сегодня».
     */
    private function parseDue(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    /**
     * Форма задачи для фронта — одна на список, врезку и ленту партнёра.
     *
     * @return array<string, mixed>
     */
    public function payload(CrmTask $task, User $viewer): array
    {
        $related = $task->related;

        return [
            'id' => (int) $task->getKey(),
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'status_color' => $task->status->color(),
            'outcome' => $task->outcome?->value,
            'outcome_label' => $task->outcome?->label(),
            'outcome_color' => $task->outcome?->color(),
            'postponed_count' => (int) $task->postponed_count,
            'priority' => $task->priority->value,
            'priority_label' => $task->priority->label(),
            'priority_color' => $task->priority->color(),
            // ISO — для формы редактирования, лейбл — для показа: собирать второе
            // из первого на фронте означало бы дублировать формат даты в JSX.
            'due_at' => $task->due_at?->format('Y-m-d\TH:i'),
            'due_at_label' => $task->due_at?->format('d.m.Y H:i'),
            'done_at_label' => $task->done_at?->format('d.m.Y H:i'),
            'is_overdue' => $task->isOverdue(),
            'due_bucket' => self::dueBucket($task),
            // withExists() в списках; на одиночных путях — точечный запрос.
            'is_pinned' => (bool) ($task->getAttribute('is_pinned')
                ?? $task->pinnedBy()->whereKey((int) $viewer->getKey())->exists()),
            'created_at_label' => $task->created_at?->format('d.m.Y H:i'),
            'author' => [
                'id' => (int) $task->author_id,
                'name' => $task->author->name,
            ],
            'assignee' => [
                'id' => (int) $task->assignee_id,
                'name' => $task->assignee->name,
            ],
            'co_assignees' => $task->coAssignees
                ->map(fn (User $user): array => [
                    'id' => (int) $user->getKey(),
                    'name' => (string) $user->name,
                ])
                ->values()
                ->all(),
            'watchers' => $task->watchers
                ->map(fn (User $user): array => [
                    'id' => (int) $user->getKey(),
                    'name' => (string) $user->name,
                ])
                ->values()
                ->all(),
            'is_watched' => $task->isWatchedBy((int) $viewer->getKey()),
            'tags' => $task->tags
                ->map(fn ($tag): string => (string) $tag->name)
                ->values()
                ->all(),
            'estimate_minutes' => $task->estimate_minutes,
            'estimate_label' => self::estimateLabel($task->estimate_minutes),
            // Счётчики приходят из withCount() в списках; на одиночных путях
            // достаточно загруженной связи — полный чек-лист там и так нужен.
            'checklist_total' => (int) ($task->getAttribute('checklist_total')
                ?? ($task->relationLoaded('checklistItems') ? $task->checklistItems->count() : $task->checklistItems()->count())),
            'checklist_done' => (int) ($task->getAttribute('checklist_done')
                ?? ($task->relationLoaded('checklistItems')
                    ? $task->checklistItems->where('is_done', true)->count()
                    : $task->checklistItems()->where('is_done', true)->count())),
            'checklist' => $task->relationLoaded('checklistItems')
                ? $this->checklistPayload($task)['items']
                : null,
            'client_id' => $task->client_user_id === null ? null : (int) $task->client_user_id,
            'entity' => $related instanceof Model
                ? CrmEntityMap::tryDescribe($related, $viewer)
                : null,
            // В списках счётчик приходит из withCount(); на одиночных путях его нет —
            // там дешевле досчитать, чем показать 0 и потерять скрепку у задачи с файлами.
            'attachments_count' => (int) ($task->attachments_count
                ?? $task->media()->where('collection_name', CrmAttachments::COLLECTION)->count()),
            'can' => [
                'update' => $viewer->can('update', $task),
                'reassign' => $viewer->can('reassign', $task),
                'delete' => $viewer->can('delete', $task),
                'watch' => $viewer->can('watch', $task),
            ],
        ];
    }

    /**
     * Корзина срока для группировки раздела: секции считает сервер, чтобы
     * граница «сегодня/завтра» жила в таймзоне приложения, а не браузера.
     */
    public static function dueBucket(CrmTask $task): string
    {
        if (! $task->status->isOpen()) {
            return 'closed';
        }

        if ($task->due_at === null) {
            return 'none';
        }

        $due = $task->due_at;

        if ($due->isPast() && ! $due->isToday()) {
            return 'overdue';
        }

        if ($due->isToday()) {
            // Просроченное «сегодня в 10:00» остаётся в «Сегодня»: день ещё не кончился.
            return 'today';
        }

        if ($due->isTomorrow()) {
            return 'tomorrow';
        }

        if ($due->lte(now()->addDays(7)->endOfDay())) {
            return 'week';
        }

        return 'later';
    }

    /**
     * Чек-лист задачи для фронта: пункты + счётчики одной формой.
     *
     * @return array{items: list<array<string, mixed>>, checklist_total: int, checklist_done: int}
     */
    public function checklistPayload(CrmTask $task): array
    {
        $items = $task->checklistItems
            ->map(fn ($item): array => [
                'id' => (int) $item->getKey(),
                'title' => (string) $item->title,
                'position' => (int) $item->position,
                'is_done' => (bool) $item->is_done,
                'done_by' => $item->doneBy === null ? null : [
                    'id' => (int) $item->doneBy->getKey(),
                    'name' => (string) $item->doneBy->name,
                ],
                'done_at_label' => $item->done_at?->format('d.m.Y H:i'),
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'checklist_total' => count($items),
            'checklist_done' => count(array_filter($items, fn (array $item): bool => $item['is_done'])),
        ];
    }

    /**
     * Человеческая подпись трудоёмкости: «30 мин», «2 ч», «1 ч 30 мин».
     */
    public static function estimateLabel(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return "{$minutes} мин";
        }

        return $rest === 0 ? "{$hours} ч" : "{$hours} ч {$rest} мин";
    }
}

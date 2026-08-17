<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskPriority;
use App\Models\CrmTask;
use App\Models\CrmTaskOccurrence;
use App\Models\CrmTaskRecurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Порождение задач по расписанию.
 *
 * Два решения определяют весь сервис:
 *
 * 1. **Горизонт короткий.** Задачи материализуются на сутки-двое вперёд,
 *    а не на месяцы. Насыпав год вперёд, список задач перестал бы отвечать
 *    на вопрос «что делать сегодня», а правка правила потребовала бы чистить
 *    уже созданное.
 * 2. **Идемпотентность на уровне БД.** Уникальный ключ
 *    `(recurrence_id, occurrence_date)` ловит повторный прогон планировщика,
 *    ручной запуск при отладке и задвоенный воркер. Проверка «а нет ли уже»
 *    в PHP гонку не закрывает — два процесса прошли бы её одновременно.
 */
class CrmTaskRecurrenceService
{
    /**
     * На сколько дней вперёд материализуем задачи.
     */
    public const HORIZON_DAYS = 2;

    /**
     * Имя уникального ключа вхождений — по нему отличаем «уже создано»
     * от настоящей ошибки записи.
     */
    private const OCCURRENCE_UNIQUE_INDEX = 'crm_task_occurrences_unique';

    /**
     * Прогнать все активные правила и создать недостающие задачи.
     *
     * @return int сколько задач создано
     */
    public function generate(?CarbonInterface $from = null, int $horizonDays = self::HORIZON_DAYS): int
    {
        $today = CarbonImmutable::parse($from ?? CarbonImmutable::now())->startOfDay();
        $created = 0;

        CrmTaskRecurrence::query()
            ->with(['assignee:id,name', 'author:id,name'])
            ->where('is_active', true)
            ->chunkById(100, function ($recurrences) use ($today, $horizonDays, &$created): void {
                foreach ($recurrences as $recurrence) {
                    $created += $this->generateFor($recurrence, $today, $horizonDays);
                }
            });

        return $created;
    }

    /**
     * Создать недостающие вхождения одного правила.
     */
    public function generateFor(CrmTaskRecurrence $recurrence, CarbonImmutable $today, int $horizonDays): int
    {
        $created = 0;

        for ($offset = 0; $offset <= $horizonDays; $offset++) {
            $date = $today->addDays($offset);

            if (! $this->shouldRunOn($recurrence, $date)) {
                continue;
            }

            if ($this->create($recurrence, $date)) {
                $created++;
            }
        }

        if ($created > 0) {
            $recurrence->forceFill(['last_generated_for' => $today->addDays($horizonDays)])->save();
        }

        return $created;
    }

    /**
     * Выпадает ли правило на дату: и по окну действия, и по маске дней.
     */
    private function shouldRunOn(CrmTaskRecurrence $recurrence, CarbonImmutable $date): bool
    {
        if ($recurrence->starts_on !== null && $date->lt($recurrence->starts_on->startOfDay())) {
            return false;
        }

        if ($recurrence->ends_on !== null && $date->gt($recurrence->ends_on->startOfDay())) {
            return false;
        }

        return $recurrence->matchesDate($date);
    }

    /**
     * Создать задачу и отметку вхождения одной транзакцией.
     *
     * Дубль ловится уникальным ключом и означает «уже создано» — это штатный
     * исход параллельного прогона, а не ошибка, поэтому просто возвращаем false.
     */
    private function create(CrmTaskRecurrence $recurrence, CarbonImmutable $date): bool
    {
        // Время дедлайна — в зоне приложения: «13:30» пользователя и время
        // сервера не должны разъезжаться. На этих граблях проект уже стоял.
        $dueAt = $date->setTimeFromTimeString((string) $recurrence->due_time);

        try {
            return DB::transaction(function () use ($recurrence, $date, $dueAt): bool {
                $task = CrmTask::create([
                    'title' => $recurrence->title,
                    'description' => $recurrence->description,
                    'author_id' => $recurrence->author_id,
                    'assignee_id' => $recurrence->assignee_id,
                    'client_user_id' => $recurrence->client_user_id,
                    'related_type' => $recurrence->related_type,
                    'related_id' => $recurrence->related_id,
                    // Правило могло быть заведено без приоритета — тогда
                    // у задачи обычный, как у заведённой руками.
                    'priority' => $recurrence->priority ?? TaskPriority::NORMAL,
                    'due_at' => $dueAt,
                    // Повторяющаяся задача наследует плановую трудоёмкость правила.
                    'estimate_minutes' => $recurrence->estimate_minutes,
                ]);

                // Шаблон чек-листа копируется в каждую порождённую задачу:
                // «еженедельная сверка» приходит с готовым списком шагов.
                foreach (array_values($recurrence->checklist ?? []) as $index => $itemTitle) {
                    $task->checklistItems()->create([
                        'title' => (string) $itemTitle,
                        'position' => $index + 1,
                    ]);
                }

                CrmTaskOccurrence::create([
                    'recurrence_id' => $recurrence->getKey(),
                    'task_id' => $task->getKey(),
                    'occurrence_date' => $date->toDateString(),
                ]);

                return true;
            });
        } catch (QueryException $exception) {
            // Нарушение уникального ключа — значит, вхождение уже создано
            // параллельным прогоном. Любая другая ошибка должна всплыть.
            if ($this->isDuplicate($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Именно дубль вхождения, а не любое нарушение целостности.
     *
     * По одному лишь SQLSTATE 23000 судить нельзя: под него попадают и NOT NULL,
     * и внешние ключи. Такая проверка молча съедала бы настоящие ошибки, и
     * правило «создалось», не породив ни одной задачи. Поэтому смотрим на текст
     * и требуем упоминания уникального ключа.
     */
    private function isDuplicate(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, self::OCCURRENCE_UNIQUE_INDEX)
            || (str_contains($message, 'UNIQUE constraint failed')
                && str_contains($message, 'crm_task_occurrences'));
    }

    /**
     * Правила, доступные актору.
     *
     * Та же граница, что у задач: без права на отдел — только своё участие.
     *
     * @return Builder<CrmTaskRecurrence>
     */
    public function visibleTo(User $actor): Builder
    {
        $query = CrmTaskRecurrence::query();

        if (! $actor->can('crm-department.view')) {
            $actorId = (int) $actor->getKey();

            $query->where(fn (Builder $inner) => $inner
                ->where('author_id', $actorId)
                ->orWhere('assignee_id', $actorId));
        }

        return $query;
    }
}

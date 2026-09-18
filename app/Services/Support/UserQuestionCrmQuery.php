<?php

namespace App\Services\Support;

use App\Enums\Crm\CrmScope;
use App\Enums\UserQuestionStatus;
use App\Models\User;
use App\Models\UserQuestion;
use Illuminate\Database\Eloquent\Builder;

/**
 * Вопросы клиентов глазами CRM: чьи вопросы сотрудник видит и на какие отвечает.
 *
 * Вопрос принадлежит менеджеру ровно постольку, поскольку ему принадлежит
 * партнёр: граница та же, что у партнёров, задач и недоборов. Вопросы гостей
 * и учёток без персонального менеджера владельца не имеют — их видит тот, кто
 * видит отдел, в разрезе «весь отдел» (и только их — с галочкой «Нераспределённые»).
 *
 * Две границы намеренно разведены: {@see accessible()} — по праву, для карточки
 * и действий; {@see visible()} — по фокусу экрана, для списка и счётчика.
 */
class UserQuestionCrmQuery
{
    /** Статусы, при которых вопрос ждёт сотрудника. */
    public const OPEN_STATUSES = [UserQuestionStatus::NEW, UserQuestionStatus::IN_PROGRESS];

    /**
     * Что сотруднику разрешено открыть: весь отдел или только свои партнёры.
     *
     * @return Builder<UserQuestion>
     */
    public function accessible(User $actor): Builder
    {
        $query = UserQuestion::query();

        if ($actor->can('crm-department.view')) {
            return $query;
        }

        return $this->ownClientsOnly($query, $actor);
    }

    /**
     * Что сотрудник видит в списке при текущем разрезе экрана.
     *
     * @return Builder<UserQuestion>
     */
    public function visible(User $actor, ?CrmScope $scope = null): Builder
    {
        $scope ??= CrmScope::resolve(null, $actor);

        if (! $actor->can('crm-department.view') || $scope->isMine()) {
            return $this->ownClientsOnly(UserQuestion::query(), $actor);
        }

        $query = UserQuestion::query();

        // «Нераспределённые» — общая для CRM галочка: оставить только тех,
        // у кого менеджера нет. Для вопросов это гости и учётки без менеджера.
        if ($actor->crm_show_unassigned) {
            $query->where(function (Builder $q) {
                $q->whereNull('user_id')
                    ->orWhereIn('user_id', User::query()->whereNull('personal_manager_id')->select('users.id'));
            });
        }

        return $query;
    }

    /**
     * Сколько вопросов ждут ответа — бейдж пункта меню.
     */
    public function openCount(User $actor): int
    {
        return $this->visible($actor)
            ->whereIn('status', array_map(fn (UserQuestionStatus $s) => $s->value, self::OPEN_STATUSES))
            ->count();
    }

    /**
     * Фильтр по статусу из запроса: неизвестное значение схлопывается в «ждут ответа».
     */
    public function statusFilter(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['open', 'answered', 'rejected', 'all'], true) ? $value : 'open';
    }

    /**
     * @param  Builder<UserQuestion>  $query
     * @return Builder<UserQuestion>
     */
    public function applyStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'answered' => $query->where('status', UserQuestionStatus::ANSWERED->value),
            'rejected' => $query->where('status', UserQuestionStatus::REJECTED->value),
            'all' => $query,
            default => $query->whereIn('status', array_map(fn (UserQuestionStatus $s) => $s->value, self::OPEN_STATUSES)),
        };
    }

    /**
     * @param  Builder<UserQuestion>  $query
     * @return Builder<UserQuestion>
     */
    public function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search) {
            $q->where('subject', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhereHas('user', fn (Builder $u) => $u
                    ->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.erp_name', 'like', "%{$search}%"));
        });
    }

    /**
     * Счётчики чипов статусов по видимой выборке.
     *
     * @param  Builder<UserQuestion>  $visible
     * @return array{open: int, answered: int, rejected: int, all: int}
     */
    public function counts(Builder $visible): array
    {
        $byStatus = (clone $visible)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n);

        $open = 0;
        foreach (self::OPEN_STATUSES as $status) {
            $open += $byStatus->get($status->value, 0);
        }

        return [
            'open' => $open,
            'answered' => $byStatus->get(UserQuestionStatus::ANSWERED->value, 0),
            'rejected' => $byStatus->get(UserQuestionStatus::REJECTED->value, 0),
            'all' => $byStatus->sum(),
        ];
    }

    /**
     * @param  Builder<UserQuestion>  $query
     * @return Builder<UserQuestion>
     */
    private function ownClientsOnly(Builder $query, User $actor): Builder
    {
        $managerId = $actor->managerProfile?->id;

        // Сотрудник без карточки менеджера: за ним не закреплён ни один партнёр.
        if ($managerId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'user_id',
            User::query()->clients()->where('personal_manager_id', $managerId)->select('users.id'),
        );
    }
}

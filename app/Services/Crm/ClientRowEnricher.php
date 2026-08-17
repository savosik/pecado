<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskStatus;
use App\Models\ContractorBalance;
use App\Models\CrmTask;
use App\Models\Payment;
use App\Models\User;
use App\Support\Crm\LastVisit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Общее содержимое строки партнёра: ближайшая задача, последний заказ, визит.
 *
 * Владелец **содержимого** строки. Отбор и сортировка остаются за теми, кто
 * строит список ({@see ClientListService}, {@see SalesPlanService}) — у них
 * разные наборы фильтров, а вот колонки обязаны быть одинаковыми.
 *
 * Иначе на брифинге по плану «последний заказ» показывал бы одно, а в списке
 * партнёров другое, и разницу прочли бы как ошибку в цифрах, а не как две
 * независимые реализации одной колонки.
 */
class ClientRowEnricher
{
    /**
     * Горизонт «ближайших» задач, в днях. Тот же, что у быстрого фильтра списка.
     */
    public const SOON_DAYS = 7;

    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly ClientLastOrderService $lastOrders,
    ) {}

    /**
     * Ближайшая незакрытая задача по каждому партнёру, одним запросом.
     *
     * @param  list<int>  $clientIds
     * @return array<int, CrmTask>
     */
    public function nextTasks(array $clientIds, User $actor): array
    {
        if ($clientIds === []) {
            return [];
        }

        $tasks = $this->tasks->visibleTo($actor)
            ->whereIn('client_user_id', $clientIds)
            ->whereIn('status', TaskStatus::activeValues())
            ->with('assignee:id,name')
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $byClient = [];

        foreach ($tasks as $task) {
            $clientId = (int) $task->client_user_id;

            // Первая по порядку и есть ближайшая — сортировку задал запрос.
            if (! isset($byClient[$clientId])) {
                $byClient[$clientId] = $task;
            }
        }

        return $byClient;
    }

    /**
     * Сколько активных задач у каждого партнёра, одним запросом.
     *
     * Нужно там, где ячейка задач рисуется без основного запроса списка
     * (сетка планов и брифинговый список): в списке партнёров то же число
     * приезжает через withCount самого запроса.
     *
     * @param  list<int>  $clientIds
     * @return array<int, int>
     */
    public function activeTaskCounts(array $clientIds, User $actor): array
    {
        if ($clientIds === []) {
            return [];
        }

        /** @var array<int, int> $counts */
        $counts = $this->tasks->visibleTo($actor)
            ->whereIn('client_user_id', $clientIds)
            ->whereIn('status', TaskStatus::activeValues())
            ->selectRaw('client_user_id, COUNT(*) as aggregate')
            ->groupBy('client_user_id')
            ->pluck('aggregate', 'client_user_id')
            ->map('intval')
            ->all();

        return $counts;
    }

    /**
     * Ближайшая задача и число активных по каждой записи, привязанной полиморфно.
     *
     * Отдельно от {@see nextTasks()}, потому что у задачи на лиде `client_user_id`
     * пуст — партнёра, к которому её свести, до конверсии просто нет, и связь
     * держится на паре `related_type`/`related_id`.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $relatedType
     * @param  list<int>  $relatedIds
     * @return array<int, array{active_count: int, next: array<string, mixed>|null}>
     */
    public function tasksForRelated(string $relatedType, array $relatedIds, User $actor): array
    {
        if ($relatedIds === []) {
            return [];
        }

        $tasks = $this->tasks->visibleTo($actor)
            ->where('related_type', $relatedType)
            ->whereIn('related_id', $relatedIds)
            ->whereIn('status', TaskStatus::activeValues())
            ->with('assignee:id,name')
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($tasks as $task) {
            $relatedId = (int) $task->related_id;

            $rows[$relatedId] ??= ['active_count' => 0, 'next' => null];
            $rows[$relatedId]['active_count']++;

            // Первая по порядку и есть ближайшая — сортировку задал запрос.
            $rows[$relatedId]['next'] ??= $this->nextTaskPayload($task);
        }

        return $rows;
    }

    /**
     * Ячейка «Задачи» в той же форме, что в списке партнёров.
     *
     * Форма общая намеренно: пока у планов был свой компонент, пустое значение
     * там рисовалось прочерком без возможности поставить задачу, а в партнёрах —
     * кликабельным «нет задач». Одни и те же данные вели себя по-разному.
     *
     * @return array{active_count: int, next: array<string, mixed>|null}
     */
    public function tasksPayload(?CrmTask $nextTask, int $activeCount): array
    {
        return [
            'active_count' => $activeCount,
            'next' => $nextTask === null ? null : $this->nextTaskPayload($nextTask),
        ];
    }

    /**
     * Последний заказ по каждому партнёру.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function lastOrders(array $clientIds): array
    {
        return $this->lastOrders->forClients($clientIds);
    }

    /**
     * Финансовая сводка по каждому партнёру: долг, просрочка и последний платёж.
     *
     * Долг и просрочка — суммы `current_balance`/`overdue_debt` по контрагентам
     * партнёра из `contractor_balances`. Это единственный источник долга: сумма
     * неоплаченных документов больше долга в разы, потому что 1С гасит его ещё
     * и авансами по заказам. Последний платёж — свежайшее входящее поступление
     * из `payments`; возвраты клиенту платежом не считаются.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array{debt: float, overdue_debt: float, last_payment: array{date: string, days_ago: int, amount: float}|null}>
     */
    public function finance(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = [];

        ContractorBalance::query()
            ->whereIn('user_id', $clientIds)
            ->selectRaw('user_id, SUM(current_balance) as debt, SUM(overdue_debt) as overdue')
            ->groupBy('user_id')
            ->get()
            ->each(function (ContractorBalance $balance) use (&$rows): void {
                $rows[(int) $balance->user_id] = [
                    'debt' => round((float) $balance->getAttribute('debt'), 2),
                    'overdue_debt' => round((float) $balance->getAttribute('overdue'), 2),
                    'last_payment' => null,
                ];
            });

        // Последнее поступление каждого партнёра одним запросом: оконная
        // нумерация вместо N подзапросов или выборки всей истории платежей.
        $latest = DB::query()
            ->fromSub(
                Payment::query()
                    ->incoming()
                    ->whereIn('user_id', $clientIds)
                    ->select('user_id', 'date', 'amount')
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY date DESC, id DESC) as rn')
                    ->toBase(),
                'last_payments',
            )
            ->where('rn', 1)
            ->get();

        $today = CarbonImmutable::now()->startOfDay();

        foreach ($latest as $payment) {
            $date = CarbonImmutable::parse((string) $payment->date);

            // Партнёр может платить, не имея строки баланса (1С ещё не прислала
            // partner.balance) — платёж всё равно показываем, долг нулевой.
            $rows[(int) $payment->user_id] ??= ['debt' => 0.0, 'overdue_debt' => 0.0, 'last_payment' => null];
            $rows[(int) $payment->user_id]['last_payment'] = [
                'date' => $date->format('d.m.Y'),
                'days_ago' => (int) $date->startOfDay()->diffInDays($today),
                'amount' => round((float) $payment->amount, 2),
            ];
        }

        return $rows;
    }

    /**
     * Ближайшая задача в форме, готовой к показу.
     *
     * Состояние срока считает бэкенд: на фронте это означало бы разбор дат
     * и часовой пояс сервера сразу в двух местах.
     *
     * @return array<string, mixed>
     */
    public function nextTaskPayload(CrmTask $task): array
    {
        $now = CarbonImmutable::now();
        $due = $task->due_at;

        $state = match (true) {
            $due === null => 'none',
            $due->lt($now) => 'overdue',
            $due->isToday() => 'today',
            $due->isTomorrow() => 'tomorrow',
            $due->lte($now->addDays(self::SOON_DAYS)) => 'week',
            default => 'later',
        };

        return [
            'id' => (int) $task->getKey(),
            'title' => $task->title,
            // Описание нужно подсказке при наведении: по одному заголовку
            // не понять, что именно обещали сделать.
            'description' => $task->description,
            'due_state' => $state,
            // Короткая метка для ячейки, полная — для подсказки: в колонке
            // на год и минуты места нет.
            'due_at_label' => $due === null
                ? null
                : ($due->isCurrentYear() ? $due->format('d.m H:i') : $due->format('d.m.Y')),
            'due_at_full' => $due?->format('d.m.Y H:i'),
            'overdue_days' => $state === 'overdue' && $due !== null
                ? (int) $due->startOfDay()->diffInDays($now->startOfDay())
                : null,
            'assignee_name' => $task->assignee->name,
        ];
    }

    /**
     * Метка последнего визита партнёра на сайт.
     *
     * @return array<string, mixed>
     */
    public function lastVisitPayload(?\Illuminate\Support\Carbon $lastSeenAt): array
    {
        return LastVisit::payload($lastSeenAt);
    }
}

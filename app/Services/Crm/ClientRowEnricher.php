<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Models\User;
use App\Support\Crm\LastVisit;
use Carbon\CarbonImmutable;

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

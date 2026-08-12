<?php

namespace App\Services\Crm;

use App\Enums\Crm\CrmScope;
use App\Enums\Crm\PlanTarget;
use App\Enums\UserKind;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Месячные планы продаж: хранение и ввод.
 *
 * Расчёт факта, выполнения и burndown здесь намеренно отсутствует — он живёт
 * в {@see PlanProgressService} поверх `ShipmentAnalyticsService`. Второй движок
 * расчёта продаж означал бы, что `/crm/plans` и `/crm/analytics` рано или поздно
 * разойдутся в цифрах, и объяснить это расхождение будет нечем.
 */
class SalesPlanService
{
    /**
     * Русские названия месяцев.
     *
     * Локаль приложения — `en`, поэтому `translatedFormat()` дал бы «August»
     * в интерфейсе, который обязан быть русским.
     *
     * @var list<string>
     */
    private const MONTHS = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
    ];

    public function __construct(
        private readonly ClientRowEnricher $enricher,
    ) {}

    /**
     * Планы, доступные актору на чтение.
     *
     * Менеджер видит план отдела (общая цель), свой собственный план и планы своих
     * партнёров. Планы соседних менеджеров и их партнёров — только у того, кто видит
     * весь отдел: чужая цифра выручки не его дело.
     *
     * @return Builder<CrmSalesPlan>
     */
    public function visibleTo(User $actor): Builder
    {
        $query = CrmSalesPlan::query();

        if ($actor->can('crm-clients-all.view')) {
            return $query;
        }

        $managerId = $actor->managerProfile?->id;

        return $query->where(fn (Builder $inner) => $inner
            ->where('target_type', PlanTarget::DEPARTMENT->value)
            ->when($managerId !== null, fn (Builder $q) => $q->orWhere(fn (Builder $mine) => $mine
                ->where('target_type', PlanTarget::MANAGER->value)
                ->where('target_id', $managerId)))
            ->orWhere(fn (Builder $clients) => $clients
                ->where('target_type', PlanTarget::CLIENT->value)
                ->whereIn('target_id', User::query()->visibleInCrm($actor)->select('id'))));
    }

    /**
     * Может ли актор ставить план этой цели.
     *
     * Рамки задаёт не право, а скоуп: право `crm-plans.edit` есть у всего отдела,
     * но менеджер расписывает только своих партнёров. План отдела и планы менеджеров
     * ставит тот, кто отвечает за отдел целиком.
     */
    public function canManage(User $actor, PlanTarget $type, ?int $targetId): bool
    {
        if (! $actor->can('crm-plans.edit')) {
            return false;
        }

        if ($actor->can('crm-clients-all.view')) {
            return $type->needsTarget() ? $this->targetExists($type, $targetId) : true;
        }

        if ($type !== PlanTarget::CLIENT || $targetId === null) {
            return false;
        }

        return User::query()->visibleInCrm($actor)->whereKey($targetId)->exists();
    }

    /**
     * Поставить или обновить план.
     *
     * `$amount === null` — снять план: пустая ячейка в сетке означает «плана нет»,
     * а не «план ноль». Ноль — это осознанное «в этом месяце не продаём».
     */
    public function set(
        PlanTarget $type,
        ?int $targetId,
        CarbonInterface $month,
        ?float $amount,
        User $actor,
        ?string $comment = null,
    ): ?CrmSalesPlan {
        $keys = [
            'period_month' => CrmSalesPlan::normalizeMonth($month),
            'target_type' => $type->value,
            'target_id' => CrmSalesPlan::targetKey($type, $targetId),
        ];

        if ($amount === null) {
            CrmSalesPlan::query()->where($keys)->delete();

            return null;
        }

        return CrmSalesPlan::query()->updateOrCreate($keys, [
            'amount' => round($amount, 2),
            'author_id' => (int) $actor->getKey(),
            'comment' => $comment,
        ]);
    }

    /**
     * Массовое сохранение сетки.
     *
     * Строки, которые актору не разрешены, тихо пропускаются, а не роняют запрос:
     * сетка отправляется целиком, и одна чужая ячейка не должна отменять правку
     * остальных двадцати. Что именно сохранилось, возвращается вызывающему.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{saved: int, removed: int, skipped: int}
     */
    public function bulkSet(array $rows, User $actor, CarbonInterface $defaultMonth): array
    {
        $saved = 0;
        $removed = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $actor, $defaultMonth, &$saved, &$removed, &$skipped): void {
            foreach ($rows as $row) {
                $type = PlanTarget::tryFrom((string) ($row['target_type'] ?? ''));

                if ($type === null) {
                    $skipped++;

                    continue;
                }

                $targetId = isset($row['target_id']) ? (int) $row['target_id'] : null;

                if (! $this->canManage($actor, $type, $targetId)) {
                    $skipped++;

                    continue;
                }

                $month = isset($row['month'])
                    ? $this->parseMonth((string) $row['month'])
                    : $defaultMonth;

                $amount = $this->parseAmount($row['amount'] ?? null);
                $comment = isset($row['comment']) ? (string) $row['comment'] : null;

                $plan = $this->set($type, $targetId, $month, $amount, $actor, $comment);

                $plan === null ? $removed++ : $saved++;
            }
        });

        return ['saved' => $saved, 'removed' => $removed, 'skipped' => $skipped];
    }

    /**
     * Скопировать планы прошлого месяца в указанный.
     *
     * Уже заданные значения по умолчанию не трогаются: копирование — это заполнение
     * пустой сетки, а не откат чужой работы. Перезапись возможна только явным
     * подтверждением с фронта.
     *
     * @return array{copied: int, skipped: int}
     */
    public function copyFromPreviousMonth(CarbonInterface $month, User $actor, bool $overwrite = false): array
    {
        $target = CrmSalesPlan::normalizeMonth($month);
        $source = $target->copy()->subMonthNoOverflow();

        $existing = $this->visibleTo($actor)
            ->forPeriod($target)
            ->get()
            ->keyBy(fn (CrmSalesPlan $plan): string => $this->planKey($plan));

        $copied = 0;
        $skipped = 0;

        DB::transaction(function () use ($source, $target, $actor, $overwrite, $existing, &$copied, &$skipped): void {
            foreach ($this->visibleTo($actor)->forPeriod($source)->get() as $plan) {
                $targetId = $plan->target_type->needsTarget() ? $plan->target_id : null;

                if (! $this->canManage($actor, $plan->target_type, $targetId)) {
                    $skipped++;

                    continue;
                }

                if (! $overwrite && $existing->has($this->planKey($plan))) {
                    $skipped++;

                    continue;
                }

                $this->set($plan->target_type, $targetId, $target, $plan->amountValue(), $actor, $plan->comment);
                $copied++;
            }
        });

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Планы периода, разложенные по цели: 'department' => сумма, 'manager:7' => сумма.
     *
     * @return array<string, CrmSalesPlan>
     */
    public function indexedByTarget(User $actor, CarbonInterface $month): array
    {
        return $this->visibleTo($actor)
            ->forPeriod($month)
            ->get()
            ->keyBy(fn (CrmSalesPlan $plan): string => $this->planKey($plan))
            ->all();
    }

    /**
     * Строки менеджеров для сетки: план, план прошлого месяца и сумма планов их партнёров.
     *
     * Сумма планов по партнёрам считается отдельным сгруппированным запросом, а не
     * обходом партнёров: у менеджера их сотни, и подтягивать всех ради одной цифры
     * незачем.
     *
     * @param  array<string, CrmSalesPlan>  $plans
     * @param  array<string, CrmSalesPlan>  $previous
     * @return list<array<string, mixed>>
     */
    public function managerRows(User $actor, CarbonInterface $month, array $plans, array $previous): array
    {
        // 0 вместо отсутствующей карточки менеджера: сотрудник без привязки
        // к personal_managers не должен увидеть строки всего отдела.
        $ownManagerId = $actor->managerProfile->id ?? 0;

        $managers = PersonalManager::query()
            ->select('id', 'name')
            // Скрытые карточки в сетку не попадают: ставить план уволившемуся
            // и техническому дублю — ровно тот мусор, ради которого флаг заведён.
            ->active()
            ->when(
                ! $actor->can('crm-clients-all.view'),
                fn (Builder $query) => $query->whereKey($ownManagerId),
            )
            ->orderBy('name')
            ->get();

        $clientSums = $this->clientPlanSumsByManager($month);

        return $managers->map(function (PersonalManager $manager) use ($actor, $plans, $previous, $clientSums): array {
            $key = PlanTarget::MANAGER->value.':'.$manager->id;

            return [
                'id' => $manager->id,
                'name' => $manager->name,
                'amount' => isset($plans[$key]) ? $plans[$key]->amountValue() : null,
                'previous_amount' => isset($previous[$key]) ? $previous[$key]->amountValue() : null,
                'comment' => $plans[$key]->comment ?? null,
                'clients_sum' => (float) ($clientSums[$manager->id] ?? 0),
                'can_edit' => $this->canManage($actor, PlanTarget::MANAGER, $manager->id),
            ];
        })->all();
    }

    /**
     * Партнёры для сетки — с планом месяца и планом прошлого месяца.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function clientRows(User $actor, CarbonInterface $month, array $filters): LengthAwarePaginator
    {
        $target = CrmSalesPlan::normalizeMonth($month);
        $previousMonth = $target->copy()->subMonthNoOverflow();

        $query = User::query()
            ->inCrmScope($actor, CrmScope::resolve($filters['scope'] ?? null, $actor))
            ->select('id', 'name', 'erp_name', 'email', 'personal_manager_id', 'last_seen_at')
            ->with('personalManager:id,name');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('erp_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        // Фильтр по менеджеру — отбор поверх уже видимых партнёров, а не доступ
        // к чужим: тому, кто отдел не видит, подставленный в адрес чужой id
        // всё равно ничего не покажет — скоуп отсечёт раньше.
        if ($actor->can('crm-department.view') && ! empty($filters['manager_id'])) {
            $query->where('personal_manager_id', (int) $filters['manager_id']);
        }

        if (($filters['only_with_plan'] ?? false)) {
            $query->whereIn('id', CrmSalesPlan::query()
                ->forPeriod($target)
                ->where('target_type', PlanTarget::CLIENT->value)
                ->select('target_id'));
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = min(max($perPage, 10), 100);

        /** @var \Illuminate\Pagination\LengthAwarePaginator<int, User> $paginator */
        $paginator = $query->orderBy('name')->paginate($perPage)->withQueryString();

        $ids = collect($paginator->items())->map(fn (User $client): int => (int) $client->getKey())->all();

        $plans = $this->clientPlansFor($ids, $target);
        $previous = $this->clientPlansFor($ids, $previousMonth);

        // Те же данные и тем же сервисом, что в списке партнёров: на брифинге
        // по плану колонки обязаны совпадать со списком, иначе разницу прочтут
        // как ошибку в цифрах.
        $canSeeTasks = $actor->can('crm-tasks.view');
        $nextTasks = $canSeeTasks ? $this->enricher->nextTasks($ids, $actor) : [];
        $taskCounts = $canSeeTasks ? $this->enricher->activeTaskCounts($ids, $actor) : [];
        $lastOrders = $this->enricher->lastOrders($ids);

        return $paginator->through(
            fn (User $client): array => $this->clientRow(
                $client,
                $actor,
                $plans,
                $previous,
                $canSeeTasks
                    ? $this->enricher->tasksPayload(
                        $nextTasks[(int) $client->getKey()] ?? null,
                        $taskCounts[(int) $client->getKey()] ?? 0,
                    )
                    : null,
                $lastOrders[(int) $client->getKey()] ?? null,
            ),
        );
    }

    /**
     * Строка партнёра в сетке.
     *
     * Вынесена из замыкания намеренно: точная форма массива, выведенная из
     * инлайн-функции, ломает вариантность дженерика пагинатора.
     *
     * @param  array<int, CrmSalesPlan>  $plans
     * @param  array<int, CrmSalesPlan>  $previous
     * @return array<string, mixed>
     */
    private function clientRow(
        User $client,
        User $actor,
        array $plans,
        array $previous,
        ?array $tasks = null,
        ?array $lastOrder = null,
    ): array {
        $id = (int) $client->getKey();

        return [
            'id' => $id,
            'name' => $client->display_name,
            'manager' => $client->personalManager?->name,
            'amount' => isset($plans[$id]) ? $plans[$id]->amountValue() : null,
            'previous_amount' => isset($previous[$id]) ? $previous[$id]->amountValue() : null,
            'comment' => $plans[$id]->comment ?? null,
            'can_edit' => $this->canManage($actor, PlanTarget::CLIENT, $id),
            // Ради этих трёх полей раздел и переделывался: на брифинге видно,
            // когда клиент был, когда заказывал и что на него поставлено.
            'tasks' => $tasks,
            'last_order' => $lastOrder,
            'last_visit' => $this->enricher->lastVisitPayload($client->last_seen_at),
        ];
    }

    /**
     * Разбор месяца из адреса: '2026-08' или '2026-08-01'. Мусор — текущий месяц.
     */
    public function parseMonth(?string $value): Carbon
    {
        if ($value === null || trim($value) === '') {
            return CrmSalesPlan::normalizeMonth(Carbon::now());
        }

        try {
            $parsed = Carbon::parse(strlen(trim($value)) === 7 ? trim($value).'-01' : trim($value));
        } catch (\Throwable) {
            return CrmSalesPlan::normalizeMonth(Carbon::now());
        }

        return CrmSalesPlan::normalizeMonth($parsed);
    }

    public function monthLabel(CarbonInterface $month): string
    {
        return self::MONTHS[$month->month - 1].' '.$month->year;
    }

    /**
     * Ключ цели плана: 'department', 'manager:7', 'client:120'.
     */
    private function planKey(CrmSalesPlan $plan): string
    {
        return $plan->target_type->needsTarget()
            ? $plan->target_type->value.':'.$plan->target_id
            : $plan->target_type->value;
    }

    /**
     * Сумма планов по партнёрам месяца в разрезе менеджеров.
     *
     * @return array<int, float>
     */
    private function clientPlanSumsByManager(CarbonInterface $month): array
    {
        return CrmSalesPlan::query()
            ->forPeriod($month)
            ->where('target_type', PlanTarget::CLIENT->value)
            ->join('users', 'users.id', '=', 'crm_sales_plans.target_id')
            ->where('users.user_kind', UserKind::CLIENT->value)
            ->whereNotNull('users.personal_manager_id')
            ->groupBy('users.personal_manager_id')
            ->selectRaw('users.personal_manager_id as manager_id, sum(crm_sales_plans.amount) as total')
            ->pluck('total', 'manager_id')
            ->map(fn ($total): float => (float) $total)
            ->all();
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, CrmSalesPlan>
     */
    private function clientPlansFor(array $clientIds, CarbonInterface $month): array
    {
        if ($clientIds === []) {
            return [];
        }

        return CrmSalesPlan::query()
            ->forPeriod($month)
            ->where('target_type', PlanTarget::CLIENT->value)
            ->whereIn('target_id', $clientIds)
            ->get()
            ->keyBy('target_id')
            ->all();
    }

    private function targetExists(PlanTarget $type, ?int $targetId): bool
    {
        if ($targetId === null) {
            return false;
        }

        return match ($type) {
            PlanTarget::MANAGER => PersonalManager::query()->whereKey($targetId)->exists(),
            PlanTarget::CLIENT => User::query()->whereKey($targetId)->exists(),
            PlanTarget::DEPARTMENT => false,
        };
    }

    /**
     * Сумма из формы: пустая строка — снять план, а не поставить ноль.
     */
    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}

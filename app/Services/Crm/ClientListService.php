<?php

namespace App\Services\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\User;
use App\Support\Crm\ClientListFilters;
use App\Support\Crm\LastVisit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Рабочий список партнёров: отбор, сортировка и сборка строки таблицы.
 *
 * Вынесено из контроллера целиком, потому что список перестал быть витриной:
 * в строке живут ближайшая задача, последняя активность и выполнение плана,
 * и каждый из этих блоков — отдельный пакетный запрос. В контроллере они
 * превратились бы в набор циклов с N+1 в первом же релизе.
 *
 * Все выборки входят через User::visibleInCrm() — единственная граница видимости
 * в разделе. Ни один фильтр её не расширяет: `manager_id` гасится в
 * {@see ClientListFilters}, поиск по задачам — здесь же, ниже.
 */
class ClientListService
{
    /**
     * Горизонт «ближайших» задач для быстрого фильтра, в днях.
     */
    private const SOON_DAYS = 7;

    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly ClientPlanFactService $planFact,
        private readonly ClientLastOrderService $lastOrders,
    ) {}

    /**
     * Страница списка, уже собранная в форму строки таблицы.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, ClientListFilters $filters): LengthAwarePaginator
    {
        $query = $this->query($actor, $filters);

        $paginator = $filters->sortBy === 'plan_percent'
            ? $this->paginateByPlanPercent($query, $filters)
            : $query->paginate($filters->perPage)->withQueryString();

        return $this->hydrate($paginator, $actor, $filters);
    }

    /**
     * Запрос партнёров с применёнными фильтрами (без пагинации).
     *
     * @return Builder<User>
     */
    public function query(User $actor, ClientListFilters $filters): Builder
    {
        $canSeeProfile = $actor->can('crm-profile.view');
        $canSeeTasks = $actor->can('crm-tasks.view');

        $query = User::query()
            ->inCrmScope($actor, $filters->scope)
            ->with(['personalManager:id,name', 'clientStatus:id,name,color'])
            ->when($canSeeProfile, fn (Builder $q) => $q->with(
                'crmProfile:id,user_id,lifecycle_status,lifecycle_hint'
            ))
            ->when($canSeeTasks, fn (Builder $q) => $q->withCount($this->tasks->activeTasksCount()));

        if ($filters->search !== null) {
            $this->applySearch($query, $filters->search, $actor);
        }

        if ($filters->managerId !== null) {
            $query->where('personal_manager_id', $filters->managerId);
        }

        if ($filters->lifecycle !== null) {
            $this->applyLifecycle($query, $filters->lifecycle);
        }

        if ($filters->coverage !== null) {
            $this->applyCoverage($query, $filters->coverage, $actor);
        }

        if ($filters->taskState !== null) {
            $this->applyTaskState($query, $filters->taskState, $actor);
        }

        if ($filters->inactiveDays !== null) {
            $this->applyInactive($query, $filters->inactiveDays);
        }

        if ($filters->noOrderDays !== null) {
            $this->applyNoOrder($query, $filters->noOrderDays);
        }

        if ($filters->orderAmountFrom !== null || $filters->orderAmountTo !== null) {
            $this->applyLastOrderAmount($query, $filters->orderAmountFrom, $filters->orderAmountTo);
        }

        if ($filters->planState !== null) {
            $this->applyPlanState($query, $filters->planState);
        }

        $this->applySort($query, $filters, $actor);

        return $query;
    }

    /**
     * Поиск по партнёру и по всему, что менеджеры о нём написали.
     *
     * Поиск по задачам сужен ровно так же, как лента и CrmTaskPolicy: без права
     * на весь отдел учитываются только свои задачи. Иначе партнёр «всплывал» бы
     * в выдаче по тексту чужого поручения — то есть по данным, которых менеджер
     * не видит.
     *
     * @param  Builder<User>  $query
     */
    private function applySearch(Builder $query, string $search, User $actor): void
    {
        $like = '%'.$search.'%';
        $seesAll = $actor->can('crm-department.view');
        $actorId = (int) $actor->getKey();

        $query->where(function (Builder $inner) use ($search, $like, $seesAll, $actorId): void {
            $inner->where('users.name', 'like', $like)
                // Рабочее наименование ищется наравне с именем: менеджер копирует
                // его из отчёта 1С, а не вспоминает, как партнёр подписался сам.
                ->orWhere('users.erp_name', 'like', $like)
                ->orWhere('users.email', 'like', $like);

            // Телефон нормализуем с обеих сторон: в базе номера приходят из 1С
            // и в скобках, и с дефисами, и слитно.
            $digits = preg_replace('/\D+/', '', $search) ?? '';

            if (mb_strlen($digits) >= 4) {
                $inner->orWhereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(users.phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE ?",
                    ['%'.$digits.'%'],
                );
            }

            $inner->orWhereHas('crmTasks', function (Builder $tasks) use ($like, $seesAll, $actorId): void {
                $tasks->where(fn (Builder $w) => $w
                    ->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like));

                if (! $seesAll) {
                    $tasks->where(fn (Builder $w) => $w
                        ->where('author_id', $actorId)
                        ->orWhere('assignee_id', $actorId));
                }
            });

            $inner->orWhereHas('crmComments', fn (Builder $q) => $q->where('body', 'like', $like));
            $inner->orWhereHas('crmEmails', fn (Builder $q) => $q->where('subject', 'like', $like));

            // По номерам документов ищем, только если запрос похож на номер:
            // LIKE по orders и shipments на каждый набор букв — это два полных
            // скана самых больших таблиц проекта ради заведомо пустого результата.
            if (preg_match('/\d{3,}/', $search) === 1) {
                $inner->orWhereHas('orders', fn (Builder $q) => $q
                    ->where('erp_number', 'like', $like)
                    ->orWhere('number', 'like', $like));
                $inner->orWhereHas('shipments', fn (Builder $q) => $q
                    ->where('erp_number', 'like', $like)
                    ->orWhere('number', 'like', $like));
            }
        });
    }

    /**
     * Фильтр по жизненному статусу.
     *
     * Партнёр без профиля считается активным — так же, как задаёт дефолт колонки.
     * Без этой ветки фильтр «Активен» прятал бы всех, кого ещё никто не описывал,
     * то есть почти всю базу.
     *
     * @param  Builder<User>  $query
     */
    private function applyLifecycle(Builder $query, ClientLifecycleStatus $status): void
    {
        $matchesProfile = fn (Builder $profile) => $profile->where('lifecycle_status', $status->value);

        if ($status === ClientLifecycleStatus::ACTIVE) {
            $query->where(fn (Builder $q) => $q
                ->whereDoesntHave('crmProfile')
                ->orWhereHas('crmProfile', $matchesProfile));

            return;
        }

        $query->whereHas('crmProfile', $matchesProfile);
    }

    /**
     * Покрытие задачами — «по кому есть следующий шаг».
     *
     * @param  Builder<User>  $query
     */
    private function applyCoverage(Builder $query, string $coverage, User $actor): void
    {
        $active = $this->activeTasksConstraint($actor);

        $coverage === 'uncovered'
            ? $query->whereDoesntHave('crmTasks', $active)
            : $query->whereHas('crmTasks', $active);
    }

    /**
     * Фильтр по состоянию ближайшей задачи.
     *
     * @param  Builder<User>  $query
     */
    private function applyTaskState(Builder $query, string $state, User $actor): void
    {
        $active = $this->activeTasksConstraint($actor);

        if ($state === 'none') {
            $query->whereDoesntHave('crmTasks', $active);

            return;
        }

        $now = CarbonImmutable::now();

        $query->whereHas('crmTasks', function (Builder $tasks) use ($active, $state, $now): void {
            $active($tasks);

            match ($state) {
                'overdue' => $tasks->whereNotNull('due_at')->where('due_at', '<', $now),
                'today' => $tasks->whereBetween('due_at', [$now->startOfDay(), $now->endOfDay()]),
                'week' => $tasks->whereNotNull('due_at')
                    ->where('due_at', '<=', $now->addDays(self::SOON_DAYS)->endOfDay()),
                default => null,
            };
        });
    }

    /**
     * «Давно не покупает»: ни одной реализации за N дней.
     *
     * Считаем по бизнес-дате 1С — по created_at вся историческая база выглядит
     * созданной в мае 2026 из-за разового импорта.
     *
     * @param  Builder<User>  $query
     */
    private function applyInactive(Builder $query, int $days): void
    {
        $since = CarbonImmutable::now()->subDays($days);

        $query->whereDoesntHave('shipments', fn (Builder $shipments) => $shipments
            ->where(fn (Builder $q) => $q
                ->where('erp_created_at', '>=', $since)
                ->orWhere('date', '>=', $since->toDateString())));
    }

    /**
     * «Не заказывал N дней» — по заказам, а не по отгрузкам.
     *
     * Отличается от {@see applyInactive()} источником: заказ это намерение
     * клиента, отгрузка — факт продажи. Клиент мог заказать вчера, а отгрузку
     * ещё не получить, и для менеджера это разные ситуации.
     *
     * @param  Builder<User>  $query
     */
    private function applyNoOrder(Builder $query, int $days): void
    {
        $since = CarbonImmutable::now()->subDays($days);

        $query->whereDoesntHave('orders', fn (Builder $orders) => $orders
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) >= ?', [$since]));
    }

    /**
     * Сумма последнего заказа в рублях, от и до.
     *
     * Считается тем же выражением, что колонка ({@see ClientLastOrderService}):
     * иначе отбор «от 100 000» показывал бы строки с суммой 90 000.
     *
     * @param  Builder<User>  $query
     */
    private function applyLastOrderAmount(Builder $query, ?float $from, ?float $to): void
    {
        // Скалярный подзапрос, а не whereHas: внутри whereHas алиас `orders`
        // занят внешней связью, и join валют к нему уже не прицепить.
        $amount = '(
            select o.total_amount * COALESCE(c.exchange_rate, 1)
            from orders o
            left join currencies c on c.code = o.currency_code
            where o.user_id = users.id and o.deleted_at is null
            order by COALESCE(o.erp_created_at, o.created_at) desc, o.id desc
            limit 1
        )';

        // CAST обязателен: Laravel биндит float как строку, а SQLite считает
        // любое число меньше любого текста — отбор «от 100 000» молча возвращал
        // бы пусто. MySQL типы привёл бы сам, и расхождение всплыло бы только
        // на проде.
        if ($from !== null) {
            $query->whereRaw("{$amount} >= CAST(? AS DECIMAL(18,2))", [$from]);
        }

        if ($to !== null) {
            $query->whereRaw("{$amount} <= CAST(? AS DECIMAL(18,2))", [$to]);
        }
    }

    /**
     * Фильтр по состоянию плана.
     *
     * Наличие плана проверяется запросом к таблице планов — это дёшево.
     * «Отстаёт» и «опережает» требуют факта по всему скоупу, поэтому идут
     * через {@see ClientPlanFactService::forScope()} и кэш.
     *
     * @param  Builder<User>  $query
     */
    private function applyPlanState(Builder $query, string $state): void
    {
        $month = CarbonImmutable::now();

        if ($state === 'with_plan' || $state === 'without_plan') {
            $exists = fn () => DB::table('crm_sales_plans')
                ->whereColumn('crm_sales_plans.target_id', 'users.id')
                ->where('crm_sales_plans.target_type', 'client')
                ->whereDate('crm_sales_plans.period_month', $month->startOfMonth()->toDateString());

            $state === 'with_plan'
                ? $query->whereExists($exists())
                : $query->whereNotExists($exists());

            return;
        }

        $percent = $this->planFact->forScope($query, $month);

        $matching = [];

        foreach ($percent as $clientId => $row) {
            if ($row['percent'] === null) {
                continue;
            }

            // 100 % — это выполненный план, он не «отстаёт» и не «опережает»:
            // попадание в обе корзины сразу сделало бы фильтры бессмысленными.
            if (($state === 'behind' && $row['percent'] < 100) || ($state === 'ahead' && $row['percent'] >= 100)) {
                $matching[] = $clientId;
            }
        }

        $query->whereIn('users.id', $matching === [] ? [0] : $matching);
    }

    /**
     * Сортировка списка.
     *
     * `next_task_due` считается подзапросом, а не в PHP: сортировка после
     * пагинации переставляла бы строки внутри страницы, а не в списке.
     *
     * @param  Builder<User>  $query
     */
    private function applySort(Builder $query, ClientListFilters $filters, User $actor): void
    {
        $direction = $filters->sortOrder;

        if ($filters->sortBy === 'next_task_due') {
            $query->addSelect(['next_task_due' => $this->nextTaskDueSub($actor)]);
            // Партнёры без срока — всегда в конце, независимо от направления:
            // «сначала самые срочные» и «сначала дальние» одинаково не про них.
            $query->orderByRaw('next_task_due is null')->orderBy('next_task_due', $direction);

            return;
        }

        if ($filters->sortBy === 'active_tasks_count') {
            $query->orderBy('active_tasks_count', $direction);

            return;
        }

        if ($filters->sortBy === 'plan_percent') {
            // Порядок задаёт paginateByPlanPercent(); здесь фиксируем стабильный
            // вторичный ключ, чтобы выборка id была детерминированной.
            $query->orderBy('users.id', 'desc');

            return;
        }

        if ($filters->sortBy === 'last_order_at') {
            $query->addSelect(['last_order_at' => DB::table('orders')
                ->selectRaw('MAX(COALESCE(orders.erp_created_at, orders.created_at))')
                ->whereColumn('orders.user_id', 'users.id')
                ->whereNull('orders.deleted_at')]);
            // Партнёры без заказов — всегда в конце: это не «давно», а «никогда».
            $query->orderByRaw('last_order_at is null')->orderBy('last_order_at', $direction);

            return;
        }

        if ($filters->sortBy === 'name') {
            // Сортируем по тому, что видно в колонке: у партнёра из 1С это
            // рабочее наименование, у зарегистрировавшегося на сайте — его имя.
            $query->orderByRaw('COALESCE(NULLIF(users.erp_name, \'\'), users.name) '.$direction);

            return;
        }

        $query->orderBy('users.'.$filters->sortBy, $direction);
    }

    /**
     * Ручная пагинация по выполнению плана.
     *
     * Процент — не колонка, отсортировать по нему в SQL нечем. Поэтому порядок
     * считается по всему скоупу (тот же кэшированный агрегат, что у фильтра),
     * а страница догружается по срезу идентификаторов.
     *
     * @param  Builder<User>  $query
     * @return Paginator<int, User>
     */
    private function paginateByPlanPercent(Builder $query, ClientListFilters $filters): Paginator
    {
        $month = CarbonImmutable::now();
        $planFact = $this->planFact->forScope($query, $month);

        /** @var list<int> $ids */
        $ids = $query->clone()->reorder()->pluck('users.id')->map('intval')->all();

        $desc = $filters->sortOrder === 'desc';

        usort($ids, function (int $a, int $b) use ($planFact, $desc): int {
            $left = $planFact[$a]['percent'] ?? null;
            $right = $planFact[$b]['percent'] ?? null;

            // Партнёры без плана — в конце при любом направлении: это не «ноль
            // процентов», а «цифру никто не ставил».
            if ($left === null && $right === null) {
                return $b <=> $a;
            }
            if ($left === null) {
                return 1;
            }
            if ($right === null) {
                return -1;
            }

            return $desc ? $right <=> $left : $left <=> $right;
        });

        $page = Paginator::resolveCurrentPage();
        $pageIds = array_slice($ids, ($page - 1) * $filters->perPage, $filters->perPage);

        $models = $pageIds === []
            ? collect()
            : $query->clone()->reorder()->whereIn('users.id', $pageIds)->get()->keyBy('id');

        $items = [];

        foreach ($pageIds as $id) {
            $model = $models->get($id);

            if ($model !== null) {
                $items[] = $model;
            }
        }

        return new Paginator($items, count($ids), $filters->perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * Догрузить страницу тем, чего нет в самой строке пользователя.
     *
     * Три пакетных запроса на страницу независимо от per_page: ближайшие задачи,
     * последние комментарии, план с фактом.
     *
     * @param  Paginator<int, User>  $paginator
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function hydrate(Paginator $paginator, User $actor, ClientListFilters $filters): LengthAwarePaginator
    {
        $canSeeTasks = $actor->can('crm-tasks.view');
        $canSeePlans = $actor->can('crm-plans.view');
        $canSeeProfile = $actor->can('crm-profile.view');

        /** @var list<int> $ids */
        $ids = collect($paginator->items())->map(fn (User $client) => (int) $client->getKey())->all();

        $nextTasks = $canSeeTasks ? $this->nextTasks($ids, $actor) : [];
        $lastComments = $this->lastComments($ids);
        $planFact = $canSeePlans ? $this->planFact->forClients($ids, CarbonImmutable::now()) : [];
        $lastOrders = $this->lastOrders->forClients($ids);

        /** @var LengthAwarePaginator<int, array<string, mixed>> $hydrated */
        $hydrated = $paginator->through(fn (User $client): array => $this->row(
            $client,
            $nextTasks[(int) $client->getKey()] ?? null,
            $lastComments[(int) $client->getKey()] ?? null,
            $planFact[(int) $client->getKey()] ?? null,
            $lastOrders[(int) $client->getKey()] ?? null,
            $canSeeTasks,
            $canSeeProfile,
        ));

        return $hydrated;
    }

    /**
     * Ближайшая незакрытая задача по каждому партнёру страницы.
     *
     * @param  list<int>  $clientIds
     * @return array<int, CrmTask>
     */
    private function nextTasks(array $clientIds, User $actor): array
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
     * Последний комментарий по каждому партнёру страницы.
     *
     * Групповой максимум подзапросом: вытащить все комментарии и свернуть в PHP
     * означало бы тянуть переписку за годы ради одной строки на партнёра.
     *
     * @param  list<int>  $clientIds
     * @return array<int, CrmComment>
     */
    private function lastComments(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $latestIds = DB::table('crm_comments')
            ->selectRaw('MAX(id) as id')
            ->whereIn('client_user_id', $clientIds)
            ->whereNull('deleted_at')
            ->groupBy('client_user_id');

        return CrmComment::query()
            ->whereIn('id', $latestIds->pluck('id')->all())
            ->get()
            ->keyBy(fn (CrmComment $comment) => (int) $comment->client_user_id)
            ->all();
    }

    /**
     * Одна строка таблицы.
     *
     * @param  array{plan: float|null, fact: float, percent: int|null}|null  $planFact
     * @return array<string, mixed>
     */
    private function row(
        User $client,
        ?CrmTask $nextTask,
        ?CrmComment $lastComment,
        ?array $planFact,
        ?array $lastOrder,
        bool $canSeeTasks,
        bool $canSeeProfile,
    ): array {
        $profile = $canSeeProfile ? $client->crmProfile : null;
        // Партнёр без профиля читается как активный — тот же дефолт, что в колонке БД.
        // Nullsafe нужен из-за самого $profile, но Larastan видит только тип свойства.
        // @phpstan-ignore-next-line nullsafe.neverNull
        $lifecycle = $profile?->lifecycle_status ?? ClientLifecycleStatus::ACTIVE;
        $hint = $profile?->lifecycle_hint;

        return [
            'id' => (int) $client->getKey(),
            // Подпись строки — рабочее наименование из 1С: по нему менеджеры
            // сличают списки сайта и 1С. Личное имя идёт отдельным полем и
            // показывается, только когда партнёр переименовал себя в кабинете.
            'name' => $client->display_name,
            'personal_name' => $client->personal_name_if_differs,
            'email' => $client->email,
            'phone' => $client->phone,
            // Номер для tel:-ссылки: в базе он приходит из 1С в произвольном формате.
            'phone_digits' => $client->phone === null
                ? null
                : (preg_replace('/\D+/', '', $client->phone) ?: null),
            'lifecycle' => $canSeeProfile ? [
                'status' => $lifecycle->value,
                'label' => $lifecycle->label(),
                'color' => $lifecycle->color(),
                'hint' => $hint === null ? null : [
                    'status' => $hint->value,
                    'label' => $hint->label(),
                ],
            ] : null,
            'client_status' => $client->clientStatus === null ? null : [
                'name' => $client->clientStatus->name,
                'color' => $client->clientStatus->color,
            ],
            'manager' => $client->personalManager === null ? null : [
                'id' => (int) $client->personalManager->getKey(),
                'name' => $client->personalManager->name,
            ],
            'tasks' => $canSeeTasks ? [
                'active_count' => (int) ($client->active_tasks_count ?? 0),
                'next' => $nextTask === null ? null : $this->nextTaskPayload($nextTask),
            ] : null,
            'activity' => $this->activityPayload($nextTask, $lastComment),
            // Пользуется ли партнёр сайтом вообще: заказ мог приехать из 1С,
            // а в кабинет он не заходил ни разу.
            'last_visit' => LastVisit::payload($client->last_seen_at),
            'plan_fact' => $planFact,
            // Заказ, а не отгрузка: намерение клиента. Факт продаж в плане
            // и аналитике считается по отгрузкам — цифры не обязаны совпадать.
            'last_order' => $lastOrder,
            'created_at_label' => $client->created_at?->format('d.m.Y'),
        ];
    }

    /**
     * Ближайшая задача в форме, готовой к показу.
     *
     * Состояние срока считает бэкенд: на фронте это означало бы разбор дат
     * и часовой пояс сервера в JSX.
     *
     * @return array<string, mixed>
     */
    private function nextTaskPayload(CrmTask $task): array
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
     * Что показать мелкой строкой под именем партнёра.
     *
     * Приоритет у задачи: следующий шаг важнее того, что записали вчера.
     *
     * @return array{kind: string, text: string|null, at_label: string|null}
     */
    private function activityPayload(?CrmTask $nextTask, ?CrmComment $lastComment): array
    {
        if ($nextTask !== null) {
            return [
                'kind' => 'task',
                'text' => Str::limit($nextTask->title, 120),
                'at_label' => $nextTask->due_at?->format('d.m.Y H:i'),
            ];
        }

        if ($lastComment !== null) {
            return [
                'kind' => 'comment',
                'text' => Str::limit($lastComment->body, 120),
                'at_label' => $lastComment->created_at?->format('d.m.Y H:i'),
            ];
        }

        return ['kind' => 'none', 'text' => null, 'at_label' => null];
    }

    /**
     * Подзапрос «срок ближайшей незакрытой задачи» для сортировки.
     */
    private function nextTaskDueSub(User $actor): \Illuminate\Database\Query\Builder
    {
        $sub = DB::table('crm_tasks')
            ->selectRaw('MIN(due_at)')
            ->whereColumn('crm_tasks.client_user_id', 'users.id')
            ->whereNull('crm_tasks.deleted_at')
            ->whereIn('crm_tasks.status', TaskStatus::activeValues());

        if (! $actor->can('crm-department.view')) {
            $actorId = (int) $actor->getKey();

            $sub->where(fn ($q) => $q
                ->where('crm_tasks.author_id', $actorId)
                ->orWhere('crm_tasks.assignee_id', $actorId));
        }

        return $sub;
    }

    /**
     * Ограничение «незакрытая задача, доступная актору».
     *
     * Та же граница, что в CrmTaskService::visibleTo() — фильтры списка и лента
     * обязаны показывать один и тот же набор задач.
     *
     * Сигнатура намеренно без дженерика: замыкание уходит и в whereHas() по связи,
     * и в ручной whereHas() внутри applyTaskState() — вывести один тип на оба
     * пути Larastan не может.
     */
    private function activeTasksConstraint(User $actor): \Closure
    {
        $seesAll = $actor->can('crm-department.view');
        $actorId = (int) $actor->getKey();

        return function (Builder $tasks) use ($seesAll, $actorId): void {
            $tasks->whereIn('status', TaskStatus::activeValues());

            if (! $seesAll) {
                $tasks->where(fn (Builder $q) => $q
                    ->where('author_id', $actorId)
                    ->orWhere('assignee_id', $actorId));
            }
        };
    }
}

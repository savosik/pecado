<?php

namespace App\Support\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\CrmScope;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Набор фильтров рабочего списка партнёров.
 *
 * Иммутабельный DTO по образцу {@see \App\Services\Analytics\AnalyticsFilters}: белые
 * списки живут здесь, а не в контроллере и не в сервисе. Значение вне списка молча
 * отбрасывается — фильтр, собранный из адресной строки, не должен ни падать, ни
 * попадать в SQL как есть.
 */
final class ClientListFilters
{
    /**
     * Поля, по которым разрешена сортировка.
     *
     * `next_task_due`, `active_tasks_count` и `plan_percent` — вычисляемые: их
     * применяет ClientListService, а не orderBy по колонке.
     *
     * @var list<string>
     */
    public const SORTS = [
        'id',
        'name',
        'email',
        'created_at',
        'next_task_due',
        'active_tasks_count',
        'plan_percent',
        'last_order_at',
    ];

    /**
     * Состояние задач по партнёру.
     *
     * `none` — «нет следующего шага», рабочий список на неделю, а не отчётная цифра.
     *
     * @var list<string>
     */
    public const TASK_STATES = ['none', 'overdue', 'today', 'week', 'any'];

    /**
     * Состояние выполнения плана.
     *
     * @var list<string>
     */
    public const PLAN_STATES = ['with_plan', 'without_plan', 'behind', 'ahead'];

    /**
     * Пороги «давно нет активности», в днях.
     *
     * @var list<int>
     */
    public const INACTIVE_DAYS = [30, 60, 90];

    /**
     * Пороги «давно не заказывал», в днях.
     *
     * Тот же набор, что у активности по отгрузкам — второй набор порогов
     * заставил бы менеджера гадать, чем «60 дней» здесь отличается от «60 дней»
     * там. Разница только в источнике: заказ — намерение, отгрузка — факт.
     *
     * @var list<int>
     */
    public const NO_ORDER_DAYS = self::INACTIVE_DAYS;

    public function __construct(
        public readonly CrmScope $scope,
        public readonly ?string $search,
        public readonly ?int $managerId,
        public readonly ?ClientLifecycleStatus $lifecycle,
        public readonly ?string $coverage,
        public readonly ?string $taskState,
        public readonly ?string $planState,
        public readonly ?int $inactiveDays,
        public readonly ?int $noOrderDays,
        public readonly ?float $orderAmountFrom,
        public readonly ?float $orderAmountTo,
        public readonly ?string $stockBuffer,
        public readonly string $sortBy,
        public readonly string $sortOrder,
        public readonly int $perPage,
    ) {}

    /**
     * Разбор запроса с учётом прав актора.
     *
     * Фильтр по менеджеру доступен только тем, кто и так видит весь отдел: иначе
     * менеджер подставил бы чужой manager_id в адрес и увидел чужих партнёров.
     * Фильтры по стадии и задачам гасятся вместе с правами на эти данные —
     * фильтровать по тому, чего не видишь, незачем.
     */
    public static function fromRequest(Request $request, User $actor, bool $seesAll): self
    {
        $canSeeProfile = $actor->can('crm-profile.view');
        $canSeeTasks = $actor->can('crm-tasks.view');
        $canSeePlans = $actor->can('crm-plans.view');

        $search = self::sanitizeSearch($request->input('search'));
        $sortBy = self::pick($request->input('sort_by'), self::SORTS) ?? 'id';
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';

        // Сортировка по невидимым данным сбрасывается на дефолт, а не молча
        // применяется к пустой колонке.
        if (! $canSeeTasks && in_array($sortBy, ['next_task_due', 'active_tasks_count'], true)) {
            $sortBy = 'id';
        }

        if (! $canSeePlans && $sortBy === 'plan_percent') {
            $sortBy = 'id';
        }

        return new self(
            scope: CrmScope::fromRequest($request, $actor),
            search: $search,
            managerId: $seesAll ? self::sanitizeId($request->input('manager_id')) : null,
            lifecycle: $canSeeProfile
                ? ClientLifecycleStatus::tryFrom((string) $request->input('lifecycle'))
                : null,
            coverage: $canSeeTasks
                ? self::pick($request->input('coverage'), ['uncovered', 'covered'])
                : null,
            taskState: $canSeeTasks ? self::pick($request->input('task_state'), self::TASK_STATES) : null,
            planState: $canSeePlans ? self::pick($request->input('plan_state'), self::PLAN_STATES) : null,
            inactiveDays: self::pickInt($request->input('inactive_days'), self::INACTIVE_DAYS),
            noOrderDays: self::pickInt($request->input('no_order_days'), self::NO_ORDER_DAYS),
            orderAmountFrom: self::sanitizeAmount($request->input('order_amount_from')),
            orderAmountTo: self::sanitizeAmount($request->input('order_amount_to')),
            // Страховой запас (buf-02): включённых ~50, менеджеру нужен их список.
            stockBuffer: self::pick($request->input('stock_buffer'), ['enabled', 'disabled']),
            sortBy: $sortBy,
            sortOrder: $sortOrder,
            perPage: min(max((int) $request->input('per_page', 15), 5), 100),
        );
    }

    /**
     * Требует ли отбор факта продаж по всему скоупу.
     *
     * Обычная страница считает план/факт только по своим 15 строкам. Сортировка
     * и фильтр по проценту выполнения так не работают: чтобы отобрать отстающих,
     * факт нужен по всем видимым партнёрам. Ветка тяжёлая, поэтому включается
     * только при явном выборе.
     */
    public function needsFactForWholeScope(): bool
    {
        return $this->sortBy === 'plan_percent'
            || in_array($this->planState, ['behind', 'ahead'], true);
    }

    /**
     * Сортировка вычисляемая, то есть применяется не через orderBy по колонке.
     */
    public function hasComputedSort(): bool
    {
        return in_array($this->sortBy, ['next_task_due', 'plan_percent'], true);
    }

    /**
     * Снимок для фронта и для сохранения в отбор.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'search' => $this->search,
            'manager_id' => $this->managerId,
            'lifecycle' => $this->lifecycle?->value,
            'coverage' => $this->coverage,
            'task_state' => $this->taskState,
            'plan_state' => $this->planState,
            'inactive_days' => $this->inactiveDays,
            'no_order_days' => $this->noOrderDays,
            'order_amount_from' => $this->orderAmountFrom,
            'order_amount_to' => $this->orderAmountTo,
            'stock_buffer' => $this->stockBuffer,
            'sort_by' => $this->sortBy,
            'sort_order' => $this->sortOrder,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * Сумма отбора: неположительное и нечисловое означают «неважно».
     *
     * Ноль как границу не пропускаем намеренно — «от 0 ₽» это не фильтр,
     * а способ оставить в адресе висячий параметр.
     */
    private static function sanitizeAmount(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount > 0 ? $amount : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function pick(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @param  list<int>  $allowed
     */
    private static function pickInt(mixed $value, array $allowed): ?int
    {
        $int = (int) $value;

        return in_array($int, $allowed, true) ? $int : null;
    }

    private static function sanitizeId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function sanitizeSearch(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 120);
    }
}

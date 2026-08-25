<?php

namespace App\Services\Crm\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Контракт счётного ядра CRM: план поступлений, просрочка, балансы (v16.0.0).
 *
 * Две реализации за одним интерфейсом:
 *
 *  - [`PaymentForecastService`](PaymentForecastService.php) — исторический расчёт
 *    по графику оплаты реализаций и расшифровкам платежей;
 *  - [`LedgerPaymentForecastService`](LedgerPaymentForecastService.php) — расчёт
 *    по ленте движений регистра взаиморасчётов из 1С.
 *
 * Выбор делается **в одной точке** — привязкой в контейнере по флагу
 * `settlements.ledger_enabled`. Иначе `if` расползся бы по двадцати методам,
 * и снять старую ветку в волне 4 стало бы невозможно.
 *
 * ## Почему в интерфейсе есть `overdueOnly()` и `dueBetween()`
 *
 * Контроллеры раньше дописывали условия к возвращённому запросу руками:
 * `whereDate('sch.due_date', '<', $today)`. Псевдоним таблицы и имя колонки —
 * деталь реализации, и у регистра они другие: плановая дата там лежит в `date`,
 * а не в `due_date`. Условия по сроку вынесены в интерфейс, чтобы контроллер
 * перестал знать схему.
 *
 * Форма строки при этом общая: обе реализации отдают одинаковый набор колонок,
 * и `row()` работает с любой из них.
 */
interface PaymentForecast
{
    /**
     * Строки плана, по которым ещё ждём денег.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients  скоуп партнёров (User::visibleInCrm)
     */
    public function plannedQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder;

    /**
     * Оставить только просроченное — плановая дата раньше сегодняшней.
     */
    public function overdueOnly(QueryBuilder $query, ?CarbonImmutable $today = null): QueryBuilder;

    /**
     * Ограничить плановую дату периодом (границы включительно).
     */
    public function dueBetween(QueryBuilder $query, string $from, string $to): QueryBuilder;

    /**
     * Долг документов, по которым плановой даты нет вовсе.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder;

    /**
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleCount(EloquentBuilder $clients, FinanceFilters $filters): int;

    /**
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleTotal(EloquentBuilder $clients, FinanceFilters $filters): float;

    /**
     * Ожидаемые поступления по дням в пределах периода фильтров.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return Collection<string, float> дата (Y-m-d) => сумма в рублях
     */
    public function dailyPlan(EloquentBuilder $clients, FinanceFilters $filters): Collection;

    /**
     * Фактические поступления по дням.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return Collection<string, array{amount: float, count: int}>
     */
    public function factsByDay(EloquentBuilder $clients, string $from, string $to): Collection;

    /**
     * @param  Collection<string, float>  $plan
     * @param  Collection<string, array{amount: float, count: int}>  $facts
     * @return list<array{key: string, label: string, from: string, to: string, plan: float, fact: float}>
     */
    public function buckets(Collection $plan, Collection $facts, FinanceFilters $filters): array;

    /**
     * Просрочка по корзинам давности.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, mixed>
     */
    public function aging(EloquentBuilder $clients, FinanceFilters $filters): array;

    /**
     * Балансы взаиморасчётов, сгруппированные по партнёру.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    /**
     * Балансы контрагентов на дату: сальдо движений и просрочка.
     *
     * `$asOf` = null — состояние «сейчас». С датой отчёт становится
     * ретроспективным: в сальдо входят движения по эту дату включительно,
     * в просрочку — непогашенные плановые строки, срок которых на неё уже прошёл.
     *
     * `$dimensions` задаёт разрез: список осей из `partner`, `organization`,
     * `company` в порядке вложенности. Ответ — дерево узлов с полем `children`.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @param  list<string>  $dimensions
     */
    public function balances(EloquentBuilder $clients, ?CarbonImmutable $asOf = null, array $dimensions = ['partner', 'company']): array;

    /**
     * Ключевые показатели пульта.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, mixed>
     */
    public function summary(EloquentBuilder $clients, FinanceFilters $filters): array;

    /**
     * Партнёры с наибольшей просрочкой — блок «Кому звонить».
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function topDebtors(EloquentBuilder $clients, FinanceFilters $filters, int $limit = 10): array;

    /**
     * Строка плана в виде, пригодном и для таблицы, и для выгрузки.
     *
     * @return array<string, mixed>
     */
    public function row(object $raw, ?CarbonImmutable $today = null): array;

    /**
     * Порядок показа: сначала по плановой дате, при равных — по номеру строки.
     */
    public function applyDefaultOrder(QueryBuilder $query): QueryBuilder;

    /**
     * Остаток к оплате по строке.
     */
    public function unpaidOf(object $row): float;

    /**
     * Сумма в рублях.
     */
    public function toRub(float $amount, ?string $currencyCode): float;

    /**
     * Оплата набора реализаций — для итога журнала «Реализации».
     *
     * Живёт здесь, а не в контроллере журнала, по той же причине, что и всё
     * остальное в этом интерфейсе: «сколько закрыто по отгруженному» считается
     * по-разному у двух ядер, и журнал не должен знать, какое из них включено.
     * Иначе он показывал бы одно число, а пульт и просрочка — другое.
     *
     * @param  EloquentBuilder<\App\Models\Shipment>  $shipments  отбор журнала без пагинации
     * @return array{buckets: list<array{currency: string, docs: int, paid: float, unpaid: float}>, without_plan: int}
     */
    public function shipmentPaymentTotals(EloquentBuilder $shipments): array;
}

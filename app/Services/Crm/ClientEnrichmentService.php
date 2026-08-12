<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Support\Crm\CityExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Дозаполнение анкеты партнёра тем, что уже лежит в его документах.
 *
 * Анкету заполняет менеджер разговором, и месяцами она стоит наполовину пустой,
 * хотя часть ответов система знает и так: город — в рабочем наименовании из 1С
 * и в адресах контрагентов, периодичность закупки — в датах заказов, интересы —
 * в том, что партнёр реально берёт. Сервис только предлагает значения; решение
 * записать их принимает вызывающий код, и ничего уже заполненного он не трогает
 * (см. {@see \App\Console\Commands\CrmEnrichProfiles}).
 *
 * Своей арифметики по выручке здесь нет: топ брендов и категорий считает
 * {@see ShipmentAnalyticsService} — второго движка продаж в проекте не бывает.
 */
class ClientEnrichmentService
{
    /** Глубина истории заказов для расчёта периодичности. */
    public const ORDER_HISTORY_MONTHS = 24;

    /** Период, по которому определяются интересы: что партнёр берёт сейчас. */
    public const INTERESTS_MONTHS = 12;

    /** Меньше трёх дат — не ряд, а совпадение: периодичность по ним не считаем. */
    public const MIN_ORDER_DATES = 3;

    public const TOP_BRANDS = 3;

    public const TOP_CATEGORIES = 2;

    public function __construct(private readonly ShipmentAnalyticsService $analytics) {}

    /**
     * Что можно предложить в анкету этого партнёра.
     *
     * @return array{
     *     city: array{value: string, source: string, candidates: array<string, string>}|null,
     *     order_cycle_days: int|null,
     *     interests: list<string>
     * }
     */
    public function suggest(User $client): array
    {
        return [
            'city' => $this->city($client),
            'order_cycle_days' => $this->orderCycleDays($client),
            'interests' => $this->interests($client),
        ];
    }

    /**
     * Город из всех источников сразу: значение берём из первого по доверию,
     * а остальные кандидаты возвращаем, чтобы отчёт показал расхождения.
     *
     * Порядок источников — от осознанного к производному. Рабочее наименование
     * ведут люди: пометка «г. Челябинск» в нём означает, что партнёра так и
     * воспринимает отдел, даже если юрлицо зарегистрировано в Копейске. Дальше
     * идут адреса контрагента, и только потом — адрес конкретной доставки,
     * который может оказаться разовой отправкой в другой город.
     *
     * @return array{value: string, source: string, candidates: array<string, string>}|null
     */
    public function city(User $client): ?array
    {
        $candidates = [];

        foreach ($this->cityTexts($client) as $source => $text) {
            $city = CityExtractor::fromText($text);

            if ($city !== null && ! isset($candidates[$source])) {
                $candidates[$source] = $city;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $source = array_key_first($candidates);

        return [
            'value' => $candidates[$source],
            'source' => $source,
            'candidates' => $candidates,
        ];
    }

    /**
     * Периодичность закупки — медиана интервалов между днями заказов.
     *
     * Медиана, а не среднее: у партнёра, который год брал раз в две недели, а
     * потом пропал на полгода, среднее показало бы несуществующий цикл. Дни, а
     * не документы: три заказа, оформленных подряд одним днём, — это один поход
     * за товаром, а не три.
     */
    public function orderCycleDays(User $client): ?int
    {
        $from = CarbonImmutable::now()->subMonthsNoOverflow(self::ORDER_HISTORY_MONTHS)->startOfDay();

        $dates = $client->orders()
            // Бизнес-дата — из 1С: историю заказов импортировали разом, и по
            // created_at вся она легла бы в один день импорта.
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) >= ?', [$from])
            ->selectRaw('DISTINCT DATE(COALESCE(orders.erp_created_at, orders.created_at)) AS order_date')
            ->orderBy('order_date')
            ->pluck('order_date');

        if ($dates->count() < self::MIN_ORDER_DATES) {
            return null;
        }

        $intervals = [];
        $previous = null;

        foreach ($dates as $date) {
            $current = CarbonImmutable::parse((string) $date);

            if ($previous !== null) {
                $intervals[] = $previous->diffInDays($current);
            }

            $previous = $current;
        }

        sort($intervals);
        $count = count($intervals);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 1
            ? $intervals[$middle]
            : ($intervals[$middle - 1] + $intervals[$middle]) / 2;

        return max(1, (int) round($median));
    }

    /**
     * Интересы — топ брендов и категорий по тому, что партнёр реально забрал.
     *
     * «Без бренда» и «Без категории» в анкету не идут: это не интерес, а пробел
     * в карточке товара.
     *
     * @return list<string>
     */
    public function interests(User $client): array
    {
        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subMonthsNoOverflow(self::INTERESTS_MONTHS)->startOfMonth();

        $context = AnalyticsContext::forScope([(int) $client->getKey()], AnalyticsContext::DATE_ERP);
        $filters = new AnalyticsFilters(dateFrom: $from, dateTo: $to);

        $brands = $this->analytics->byBrand($context, $filters, self::TOP_BRANDS + 1)
            ->pluck('label')
            ->reject(fn (string $label): bool => $label === 'Без бренда')
            ->take(self::TOP_BRANDS);

        // Категория приходит полным путём «Раздел / Подраздел / Лист» — в анкету
        // берём лист: именно им менеджер называет товарную группу.
        $categories = $this->analytics->byCategory($context, $filters, self::TOP_CATEGORIES + 1)
            ->pluck('label')
            ->reject(fn (string $label): bool => $label === 'Без категории')
            ->map(fn (string $path): string => trim((string) collect(explode(' / ', $path))->last()))
            ->take(self::TOP_CATEGORIES);

        return $brands->merge($categories)
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Тексты, в которых стоит искать город, в порядке доверия к источнику.
     *
     * @return array<string, string|null>
     */
    private function cityTexts(User $client): array
    {
        /** @var Collection<int, Company> $companies */
        $companies = $client->companies()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get(['id', 'actual_address', 'legal_address']);

        return [
            'рабочее наименование' => trim((string) $client->erp_name.' , '.(string) $client->name),
            'фактический адрес контрагента' => $this->firstAddress($companies, 'actual_address'),
            'юридический адрес контрагента' => $this->firstAddress($companies, 'legal_address'),
            'адрес доставки в заказе' => $this->lastOrderAddress($client),
            'адресная книга кабинета' => $client->deliveryAddresses()->value('address'),
            'комментарий к заказу' => $this->orderCommentCity($client),
        ];
    }

    /**
     * @param  Collection<int, Company>  $companies
     */
    private function firstAddress(Collection $companies, string $column): ?string
    {
        foreach ($companies as $company) {
            $address = trim((string) $company->getAttribute($column));

            if ($address !== '' && CityExtractor::fromText($address) !== null) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Адрес из последних заказов: в самом свежем нередко стоит «самовывоз».
     */
    private function lastOrderAddress(User $client): ?string
    {
        $addresses = $client->orders()
            ->whereNotNull('delivery_address')
            ->where('delivery_address', '!=', '')
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('delivery_address');

        foreach ($addresses as $address) {
            if (CityExtractor::fromText($address) !== null) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Город из служебного блока комментария, который дописывает сайт партнёра:
     * «Город:Тюмень». «Неизвестно» там встречается чаще города — его пропускаем.
     */
    private function orderCommentCity(User $client): ?string
    {
        $comments = $client->orders()
            ->where('comment', 'like', '%Город:%')
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('comment');

        foreach ($comments as $comment) {
            if (preg_match('/Город\s*:\s*([^\r\n|]+)/u', (string) $comment, $matches)) {
                $value = trim($matches[1]);

                if ($value !== '' && mb_strtolower($value) !== 'неизвестно') {
                    // Приводим к виду, который поймёт разбор: в комментарии город
                    // стоит без маркера, а вокруг него — служебные поля.
                    return 'г. '.$value;
                }
            }
        }

        return null;
    }

    /**
     * Партнёры отдела — те же, кого показывает база партнёров CRM.
     *
     * @return Builder<User>
     */
    public function targets(): Builder
    {
        return User::query()
            ->whereNotNull('personal_manager_id')
            ->orderBy('id');
    }
}

<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Лента изменений товарного состава заказов клиента.
 *
 * Одна строка — одно нетто-изменение товара в заказе (свёртка логов
 * `order_change_logs` через {@see OrderChangeAggregator::flatten()}). Общий
 * источник для кабинета («Изменения заказов», экспорт), legacy `/api/client-api`
 * и API v1: фильтр, сортировка и нарезка на страницы написаны один раз.
 *
 * Фильтры: `type` (скаляр или список из {@see TYPES}; неизвестные значения
 * отбрасываются), `search` (по номеру заказа и названию товара), `period`
 * (быстрый фильтр: hour, today, yesterday, week, month), `date_from`/`date_to`
 * (по дате изменения, ГГГГ-ММ-ДД).
 */
class OrderChangeFeed
{
    /** Допустимые значения фильтра по типу. */
    public const TYPES = ['added', 'removed', 'changed', 'not_accepted', 'partial'];

    /** Человекочитаемые метки типов изменения. */
    public const TYPE_LABELS = [
        'added' => 'Добавлен',
        'removed' => 'Выбыл',
        'changed' => 'Изменено количество',
        'not_accepted' => 'Не принят (API)',
        'partial' => 'Принят частично (API)',
    ];

    public function __construct(private readonly OrderChangeAggregator $aggregator) {}

    /**
     * Отфильтрованные строки, новые изменения первыми.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{order_id:int, order_number:?string, order_label:string, order_type:?string, changed_at:?Carbon, kind:string, type:string, product_id:?int, product_name:string, slug:?string, external_id:?string, from:int, to:int}>
     */
    public function rows(User $user, array $filters = []): array
    {
        $types = self::types($filters['type'] ?? null);
        $searchLower = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        [$periodFrom, $periodTo] = self::periodBounds((string) ($filters['period'] ?? 'all'));

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereHas('changeLogs', fn ($q) => $q->whereIn('type', ['items_updated', 'api_shortfall']))
            ->with('company')
            ->get();

        $rows = $this->aggregator->flatten($orders);

        $rows = array_values(array_filter($rows, function (array $r) use ($types, $searchLower, $dateFrom, $dateTo, $periodFrom, $periodTo) {
            if ($types !== [] && ! in_array($r['type'], $types, true)) {
                return false;
            }

            if ($searchLower !== ''
                && mb_stripos($r['order_number'] ?? $r['order_label'], $searchLower) === false
                && mb_stripos($r['product_name'], $searchLower) === false) {
                return false;
            }

            $changedAt = $r['changed_at'];

            // Быстрый фильтр по периоду (с точностью до времени).
            if ($periodFrom && (! $changedAt || $changedAt->lt($periodFrom))) {
                return false;
            }

            if ($periodTo && (! $changedAt || $changedAt->gte($periodTo))) {
                return false;
            }

            // Ручной диапазон дат (дополнительно к периоду).
            $date = $changedAt?->toDateString();

            if ($dateFrom && (! $date || $date < $dateFrom)) {
                return false;
            }

            if ($dateTo && (! $date || $date > $dateTo)) {
                return false;
            }

            return true;
        }));

        usort($rows, fn ($a, $b) => ($b['changed_at']?->getTimestamp() ?? 0) <=> ($a['changed_at']?->getTimestamp() ?? 0));

        return $rows;
    }

    /**
     * Страница ленты (offset): свёрнутые строки живут в памяти, курсор здесь не нужен.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function slice(array $rows, int $page, int $perPage): array
    {
        return array_slice($rows, (max($page, 1) - 1) * max($perPage, 1), max($perPage, 1));
    }

    /**
     * Типы из фильтра: скаляр или список, только известные.
     *
     * @return list<string>
     */
    public static function types(mixed $input): array
    {
        return array_values(array_filter(
            (array) ($input ?? []),
            fn ($t) => in_array($t, self::TYPES, true),
        ));
    }

    /**
     * Границы быстрого фильтра по периоду: [from, to], `to` эксклюзивен («вчера»).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function periodBounds(string $period): array
    {
        return match ($period) {
            'hour' => [now()->subHour(), null],
            'today' => [now()->startOfDay(), null],
            'yesterday' => [now()->subDay()->startOfDay(), now()->startOfDay()],
            'week' => [now()->subDays(7), null],
            'month' => [now()->subDays(30), null],
            default => [null, null], // 'all'
        };
    }
}

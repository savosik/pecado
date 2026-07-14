<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Свёртка изменений товарного состава заказов к нетто-итогу «было → стало»
 * по каждому товару.
 *
 * Источник — логи `order_change_logs` типа `items_updated` (JSON `changes` с
 * ключами added/removed/modified). По каждому товару в заказе считается
 * количество до первого и после последнего изменения:
 *   - 0 → N   — товар добавлен (added);
 *   - N → 0   — товар выбыл (removed);
 *   - N → M   — изменилось количество (changed, оба > 0);
 *   - N → N   — нетто-ноль, отбрасывается (например, сняли 5 и вернули 5).
 *
 * Пример свёртки: сняли 5 апельсинов, затем добавили 6 → одна строка
 * «изменено количество: было 5, стало 6», а не два движения.
 *
 * Логика едина для значка состава заказа (значок в списке), сводной таблицы
 * «Изменения заказов» и клиентского API. Slug и external_id товаров
 * разрешаются пакетно, без N+1.
 */
class OrderChangeAggregator
{
    /**
     * Нетто-итог, сгруппированный по заказам — форма для значка состава.
     *
     * @return array<int, array{count:int, added:array<int, array{name:string, slug:?string, qty:int}>, removed:array<int, array{name:string, slug:?string, qty:int}>, changed:array<int, array{name:string, slug:?string, from:int, to:int}>}>
     */
    public function groupedByOrder(EloquentCollection $orders): array
    {
        [$perOrder, $resolver] = $this->netPerOrder($orders);

        $final = [];
        foreach ($perOrder as $orderId => $entry) {
            $added = [];
            $removed = [];
            $changed = [];
            $notAccepted = [];
            $partial = [];

            foreach ($entry['records'] as $rec) {
                $meta = $resolver($rec);
                $name = $rec['name'] ?? '—';

                if ($rec['from'] === 0) {
                    $added[] = ['name' => $name, 'slug' => $meta['slug'], 'qty' => $rec['to']];
                } elseif ($rec['to'] === 0) {
                    $removed[] = ['name' => $name, 'slug' => $meta['slug'], 'qty' => $rec['from']];
                } else {
                    $changed[] = ['name' => $name, 'slug' => $meta['slug'], 'from' => $rec['from'], 'to' => $rec['to']];
                }
            }

            foreach ($entry['shortfall'] as $rec) {
                $meta = $resolver($rec);
                $name = $rec['name'] ?? '—';

                if ($rec['type'] === 'not_accepted') {
                    $notAccepted[] = ['name' => $name, 'slug' => $meta['slug'], 'qty' => $rec['from']];
                } else {
                    $partial[] = ['name' => $name, 'slug' => $meta['slug'], 'from' => $rec['from'], 'to' => $rec['to']];
                }
            }

            $count = count($added) + count($removed) + count($changed) + count($notAccepted) + count($partial);
            if ($count === 0) {
                continue;
            }

            $final[$orderId] = [
                'count' => $count,
                'added' => array_values($added),
                'removed' => array_values($removed),
                'changed' => array_values($changed),
                'not_accepted' => array_values($notAccepted),
                'partial' => array_values($partial),
            ];
        }

        return $final;
    }

    /**
     * Плоский список движений — по одной строке на нетто-изменение товара
     * в заказе. Форма для сводной таблицы, экспорта и клиентского API.
     *
     * `kind`: 'edit' — правка состава (added/removed/changed, свёрнуто);
     * 'api' — недостача при приёме по API (not_accepted/partial, «запрошено→принято»).
     *
     * @return array<int, array{order_id:int, order_number:string, order_type:?string, changed_at:\Illuminate\Support\Carbon, kind:string, type:string, product_id:?int, product_name:string, slug:?string, external_id:?string, from:int, to:int}>
     */
    public function flatten(EloquentCollection $orders): array
    {
        [$perOrder, $resolver] = $this->netPerOrder($orders);

        $rows = [];
        foreach ($perOrder as $entry) {
            $order = $entry['order'];
            $orderNumber = $order->erp_number ?? $order->number ?? ('#'.$order->id);
            $orderType = $order->type?->value;

            $emit = function (array $rec, string $kind, string $type) use ($order, $orderNumber, $orderType, $resolver) {
                $meta = $resolver($rec);

                return [
                    'order_id' => $order->id,
                    'order_number' => $orderNumber,
                    'order_type' => $orderType,
                    'changed_at' => $rec['changed_at'],
                    'kind' => $kind,
                    'type' => $type,
                    'product_id' => $rec['product_id'],
                    'product_name' => $rec['name'] ?? '—',
                    'slug' => $meta['slug'],
                    'external_id' => $meta['external_id'],
                    'from' => $rec['from'],
                    'to' => $rec['to'],
                ];
            };

            foreach ($entry['records'] as $rec) {
                $type = $rec['from'] === 0 ? 'added' : ($rec['to'] === 0 ? 'removed' : 'changed');
                $rows[] = $emit($rec, 'edit', $type);
            }
            foreach ($entry['shortfall'] as $rec) {
                $rows[] = $emit($rec, 'api', $rec['type']);
            }
        }

        return $rows;
    }

    /**
     * Ядро: свернуть логи каждого заказа в нетто-записи по товарам и построить
     * пакетный резолвер slug/external_id. Возвращает [perOrder, resolver].
     *
     * perOrder: order_id => ['order' => Order, 'records' => [identity => rec],
     * 'shortfall' => [rec, ...]], где rec = ['name','slug','product_id','from',
     * 'to','changed_at']. Правки состава свёрнуты по товару и без нетто-нуля;
     * записи недостачи (shortfall) идут как есть, каждая со своим 'type'.
     *
     * @return array{0: array<int, array{order:\App\Models\Order, records:array<string, array<string, mixed>>, shortfall:array<int, array<string, mixed>>}>, 1: callable}
     */
    private function netPerOrder(EloquentCollection $orders): array
    {
        $noop = fn (array $rec): array => ['slug' => null, 'external_id' => null];

        if ($orders->isEmpty()) {
            return [[], $noop];
        }

        // Грузим логи изменения состава (items_updated) и недостачи при приёме
        // по API (api_shortfall), только нужные колонки — чтобы большая история
        // не раздувала память. Индекс order_change_logs(order_id) покрывает выборку.
        $orders->load(['changeLogs' => fn ($q) => $q
            ->whereIn('type', ['items_updated', 'api_shortfall'])
            ->select(['id', 'order_id', 'type', 'changes', 'created_at']),
        ]);

        $perOrder = [];
        $needProductIds = [];
        $needNames = [];

        // Собрать product_id/name для пакетного резолва slug/external_id.
        $trackNeeds = function (array $row) use (&$needProductIds, &$needNames): void {
            if (! empty($row['product_id'])) {
                $needProductIds[$row['product_id']] = true;
            } elseif (($row['slug'] ?? null) === null && ! empty($row['name'] ?? $row['product_name'] ?? null)) {
                $needNames[$row['name'] ?? $row['product_name']] = true;
            }
        };

        foreach ($orders as $order) {
            $logs = $order->changeLogs
                ->sortBy(fn ($l) => $l->created_at)   // хронологически: старые → новые
                ->values();

            if ($logs->isEmpty()) {
                continue;
            }

            $net = [];          // items_updated → нетто «было→стало»
            $shortfall = [];     // api_shortfall → отдельные записи (не сворачиваются)

            foreach ($logs as $log) {
                $changes = $log->changes ?? [];
                $at = $log->created_at;

                if ($log->type === 'api_shortfall') {
                    foreach (($changes['not_accepted'] ?? []) as $item) {
                        $shortfall[] = $this->shortfallRow($item, 'not_accepted', (int) ($item['requested'] ?? 0), 0, $at);
                    }
                    foreach (($changes['partial'] ?? []) as $item) {
                        $shortfall[] = $this->shortfallRow($item, 'partial', (int) ($item['requested'] ?? 0), (int) ($item['fulfilled'] ?? 0), $at);
                    }

                    continue;
                }

                foreach (($changes['added'] ?? []) as $item) {
                    $this->fold($net, $item, 0, (int) ($item['quantity'] ?? 0), $at);
                }
                foreach (($changes['removed'] ?? []) as $item) {
                    $this->fold($net, $item, (int) ($item['quantity'] ?? 0), 0, $at);
                }
                foreach (($changes['modified'] ?? []) as $item) {
                    $qty = $item['changes']['quantity'] ?? null;
                    if ($qty === null) {
                        continue; // изменения только цены/скидки — не движение состава
                    }
                    $this->fold($net, $item, (int) ($qty['old'] ?? 0), (int) ($qty['new'] ?? 0), $at);
                }
            }

            // Отбрасываем нетто-нулевые записи.
            $records = [];
            foreach ($net as $identity => $rec) {
                if ($rec['from'] === $rec['to']) {
                    continue;
                }
                $records[$identity] = $rec;
                $trackNeeds($rec);
            }
            foreach ($shortfall as $rec) {
                $trackNeeds($rec);
            }

            if (empty($records) && empty($shortfall)) {
                continue;
            }

            $perOrder[$order->id] = ['order' => $order, 'records' => $records, 'shortfall' => $shortfall];
        }

        if (empty($perOrder)) {
            return [[], $noop];
        }

        // Пакетный резолв slug/external_id (старые логи не хранят slug/product_id).
        $byId = ! empty($needProductIds)
            ? Product::whereIn('id', array_keys($needProductIds))
                ->get(['id', 'slug', 'external_id'])->keyBy('id')
            : collect();
        $slugByName = ! empty($needNames)
            ? Product::whereIn('name', array_keys($needNames))->pluck('slug', 'name')->all()
            : [];

        $resolver = function (array $rec) use ($byId, $slugByName): array {
            $pid = $rec['product_id'] ?? null;
            $product = $pid ? $byId->get($pid) : null;

            $slug = $rec['slug'];
            if ($slug === null && $product) {
                $slug = $product->slug;
            }
            if ($slug === null && ! empty($rec['name'])) {
                $slug = $slugByName[$rec['name']] ?? null;
            }

            return [
                'slug' => $slug,
                'external_id' => $product?->external_id,
            ];
        };

        return [$perOrder, $resolver];
    }

    /**
     * Учесть одно изменение количества товара в нетто-карте «было → стало».
     * Первое изменение задаёт `from` (количество до), каждое последующее
     * обновляет `to` (количество после), сохраняя исходный `from`.
     * `changed_at` — максимальное время лога, затронувшего товар.
     *
     * @param  array<string, array<string, mixed>>  $net
     * @param  array<string, mixed>  $item
     */
    /**
     * Нормализовать запись недостачи при приёме по API в строку «запрошено→принято».
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function shortfallRow(array $item, string $type, int $from, int $to, ?Carbon $at): array
    {
        return [
            'type' => $type,   // 'not_accepted' | 'partial'
            'name' => $item['product_name'] ?? null,
            'slug' => $item['slug'] ?? null,
            'product_id' => $item['product_id'] ?? null,
            'from' => $from,
            'to' => $to,
            'changed_at' => $at,
        ];
    }

    private function fold(array &$net, array $item, int $before, int $after, ?Carbon $changedAt): void
    {
        $identity = ! empty($item['product_id'])
            ? 'id:'.$item['product_id']
            : 'name:'.($item['product_name'] ?? '');

        if (! isset($net[$identity])) {
            $net[$identity] = [
                'name' => $item['product_name'] ?? null,
                'slug' => $item['slug'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'from' => $before,
                'to' => $after,
                'changed_at' => $changedAt,
            ];

            return;
        }

        $net[$identity]['to'] = $after;

        if ($changedAt !== null && ($net[$identity]['changed_at'] === null || $changedAt->gt($net[$identity]['changed_at']))) {
            $net[$identity]['changed_at'] = $changedAt;
        }

        // Дозаполняем метаданные, если поздний лог их содержит, а ранний — нет.
        if (empty($net[$identity]['slug']) && ! empty($item['slug'])) {
            $net[$identity]['slug'] = $item['slug'];
        }
        if (empty($net[$identity]['name']) && ! empty($item['product_name'])) {
            $net[$identity]['name'] = $item['product_name'];
        }
        if (empty($net[$identity]['product_id']) && ! empty($item['product_id'])) {
            $net[$identity]['product_id'] = $item['product_id'];
        }
    }
}

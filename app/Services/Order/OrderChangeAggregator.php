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

            $count = count($added) + count($removed) + count($changed);
            if ($count === 0) {
                continue;
            }

            $final[$orderId] = [
                'count' => $count,
                'added' => array_values($added),
                'removed' => array_values($removed),
                'changed' => array_values($changed),
            ];
        }

        return $final;
    }

    /**
     * Плоский список движений — по одной строке на нетто-изменение товара
     * в заказе. Форма для сводной таблицы, экспорта и клиентского API.
     *
     * @return array<int, array{order_id:int, order_number:string, order_type:?string, changed_at:\Illuminate\Support\Carbon, type:string, product_id:?int, product_name:string, slug:?string, external_id:?string, from:int, to:int}>
     */
    public function flatten(EloquentCollection $orders): array
    {
        [$perOrder, $resolver] = $this->netPerOrder($orders);

        $rows = [];
        foreach ($perOrder as $entry) {
            $order = $entry['order'];
            $orderNumber = $order->erp_number ?? $order->number ?? ('#'.$order->id);
            $orderType = $order->type?->value;

            foreach ($entry['records'] as $rec) {
                $meta = $resolver($rec);
                $from = $rec['from'];
                $to = $rec['to'];

                $type = $from === 0 ? 'added' : ($to === 0 ? 'removed' : 'changed');

                $rows[] = [
                    'order_id' => $order->id,
                    'order_number' => $orderNumber,
                    'order_type' => $orderType,
                    'changed_at' => $rec['changed_at'],
                    'type' => $type,
                    'product_id' => $rec['product_id'],
                    'product_name' => $rec['name'] ?? '—',
                    'slug' => $meta['slug'],
                    'external_id' => $meta['external_id'],
                    'from' => $from,
                    'to' => $to,
                ];
            }
        }

        return $rows;
    }

    /**
     * Ядро: свернуть логи каждого заказа в нетто-записи по товарам и построить
     * пакетный резолвер slug/external_id. Возвращает [perOrder, resolver].
     *
     * perOrder: order_id => ['order' => Order, 'records' => [identity => rec]],
     * где rec = ['name','slug','product_id','from','to','changed_at'].
     * Записи с нетто-нулём (from === to) уже отброшены.
     *
     * @return array{0: array<int, array{order:\App\Models\Order, records:array<string, array<string, mixed>>}>, 1: callable}
     */
    private function netPerOrder(EloquentCollection $orders): array
    {
        $noop = fn (array $rec): array => ['slug' => null, 'external_id' => null];

        if ($orders->isEmpty()) {
            return [[], $noop];
        }

        // Грузим только логи изменения состава и только нужные колонки
        // (без summary/old_total/new_total) — чтобы большая история заказов
        // не раздувала память. Индекс order_change_logs(order_id) покрывает выборку.
        $orders->load(['changeLogs' => fn ($q) => $q
            ->where('type', 'items_updated')
            ->select(['id', 'order_id', 'changes', 'created_at']),
        ]);

        $perOrder = [];
        $needProductIds = [];
        $needNames = [];

        foreach ($orders as $order) {
            $logs = $order->changeLogs
                ->sortBy(fn ($l) => $l->created_at)   // хронологически: старые → новые
                ->values();

            if ($logs->isEmpty()) {
                continue;
            }

            $net = [];

            foreach ($logs as $log) {
                $changes = $log->changes ?? [];
                $at = $log->created_at;

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

                if ($rec['product_id']) {
                    $needProductIds[$rec['product_id']] = true;
                } elseif ($rec['slug'] === null && $rec['name']) {
                    $needNames[$rec['name']] = true;
                }
            }

            if (empty($records)) {
                continue;
            }

            $perOrder[$order->id] = ['order' => $order, 'records' => $records];
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

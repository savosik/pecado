<?php

namespace App\Services\Stock;

use App\Models\ProductExpectedArrival;
use Illuminate\Support\Carbon;

/**
 * Ожидаемые поступления товара глазами сотрудника (v16.16.0, топик №16 Agent Hub).
 *
 * Единственная точка чтения таблицы `product_expected_arrivals` для показа.
 * **Только для сотрудников (CRM).** Решение заказчика 01.10.2026: в витрину,
 * кабинет клиента и клиентский API ожидания не выводятся — этот сервис нельзя
 * подключать к их контроллерам и ресурсам (страхует `ExpectedArrivalsCrmTest`).
 *
 * ## «Дата уточняется»
 *
 * Строка без даты — это и «закупки дату не внесли», и «дата прошла, а товар не
 * принят» (решение закупок 30.09.2026: просроченное ожидание не прячем). Прошедших
 * дат 1С не шлёт: ночная выгрузка переводит вчерашние в `null`. Между полуночью
 * и выгрузкой у сайта может лежать дата, ставшая вчерашней, — её показываем так
 * же, как пришлёт выгрузка: «дата уточняется», складывая с уже имеющейся строкой
 * без даты. «Сегодня» — по времени сайта (Москва).
 *
 * Источник (`purchase` / `import`) менеджеру не показывается: строки одной даты
 * из разных источников складываются.
 */
class ExpectedArrivals
{
    public const DATE_PENDING_LABEL = 'дата уточняется';

    /**
     * Ожидания по товарам: товар → склады → строки «когда и сколько».
     *
     * Строки склада упорядочены по дате, «дата уточняется» — последней.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, list<array{
     *     warehouse_id: int,
     *     warehouse: string,
     *     total: float,
     *     rows: list<array{date: string|null, label: string, quantity: float}>
     * }>>
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $today = Carbon::today()->toDateString();

        $arrivals = ProductExpectedArrival::query()
            ->whereIn('product_id', $productIds)
            ->with('warehouse:id,name')
            ->get();

        $result = [];

        foreach ($arrivals as $arrival) {
            $date = $arrival->expected_date?->toDateString();

            if ($date !== null && $date < $today) {
                $date = null;
            }

            $warehouse = &$result[$arrival->product_id][$arrival->warehouse_id];
            $warehouse ??= [
                'warehouse_id' => $arrival->warehouse_id,
                'warehouse' => (string) $arrival->warehouse?->name,
                'total' => 0.0,
                'rows' => [],
            ];

            $key = $date ?? 'pending';
            $warehouse['rows'][$key] ??= [
                'date' => $date,
                'label' => $date !== null ? Carbon::parse($date)->format('d.m.Y') : self::DATE_PENDING_LABEL,
                'quantity' => 0.0,
            ];
            $warehouse['rows'][$key]['quantity'] = round($warehouse['rows'][$key]['quantity'] + (float) $arrival->quantity, 3);
            $warehouse['total'] = round($warehouse['total'] + (float) $arrival->quantity, 3);
            unset($warehouse);
        }

        foreach ($result as $productId => $warehouses) {
            foreach ($warehouses as $warehouseId => $warehouse) {
                $rows = array_values($warehouse['rows']);
                usort($rows, static fn (array $a, array $b) => [$a['date'] === null, $a['date']] <=> [$b['date'] === null, $b['date']]);
                $warehouses[$warehouseId]['rows'] = $rows;
            }

            $warehouses = array_values($warehouses);
            usort($warehouses, static fn (array $a, array $b) => $a['warehouse'] <=> $b['warehouse']);
            $result[$productId] = $warehouses;
        }

        return $result;
    }

    /**
     * Короткая строка для подсказки рядом с товаром: ближайшая дата и общее количество.
     *
     * @param  list<array{total: float, rows: list<array{date: string|null, label: string, quantity: float}>}>  $warehouses
     * @return array{label: string, quantity: float}|null
     */
    public function summary(array $warehouses): ?array
    {
        if ($warehouses === []) {
            return null;
        }

        $nearest = null;
        $total = 0.0;

        foreach ($warehouses as $warehouse) {
            $total += $warehouse['total'];

            foreach ($warehouse['rows'] as $row) {
                if ($row['date'] !== null && ($nearest === null || $row['date'] < $nearest)) {
                    $nearest = $row['date'];
                }
            }
        }

        return [
            'label' => $nearest !== null ? Carbon::parse($nearest)->format('d.m.Y') : self::DATE_PENDING_LABEL,
            'quantity' => round($total, 3),
        ];
    }
}

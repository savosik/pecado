<?php

namespace App\Services\Crm;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Последний заказ каждого партнёра: дата, номер и сумма в рублях.
 *
 * Отдельный сервис, а не метод списка партнёров: тем же данным нужно питать
 * сетку планов (crm-24), а разъехавшиеся колонки на двух экранах — это то,
 * что на брифинге читается как расхождение цифр.
 *
 * Три вещи, которые здесь важны:
 *
 *  1. **Бизнес-дата, а не дата записи.** Историю заказов импортировали из 1С
 *     в мае 2026, поэтому `created_at` у половины базы — дата импорта.
 *     Сортировка и показ идут по `COALESCE(erp_created_at, created_at)`,
 *     ровно как в ленте партнёра и в отборе активности.
 *  2. **Рубли считаются так же, как в аналитике** — `amount × exchange_rate`.
 *     Свой способ пересчёта здесь означал бы второй движок и цифру, которая
 *     рано или поздно разойдётся с `/crm/analytics`.
 *  3. **Это заказ, а не отгрузка.** Намерение клиента, а не факт продажи;
 *     в плане и аналитике факт считается по отгрузкам, и подпись в интерфейсе
 *     обязана это различие проговаривать.
 */
class ClientLastOrderService
{
    /**
     * Последний заказ по каждому из партнёров, одним запросом.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array{number: string|null, at: string|null, at_label: string|null, amount_rub: float}>
     */
    public function forClients(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $businessDate = 'COALESCE(orders.erp_created_at, orders.created_at)';

        $rows = Order::query()
            ->whereIn('orders.user_id', $clientIds)
            // Самый свежий заказ каждого партнёра. Коррелированный подзапрос,
            // а не оконная функция: пятнадцать строк на страницу, зато одинаково
            // работает и в MySQL, и в SQLite тестов.
            ->whereRaw('orders.id = (
                select o2.id from orders o2
                where o2.user_id = orders.user_id and o2.deleted_at is null
                order by COALESCE(o2.erp_created_at, o2.created_at) desc, o2.id desc
                limit 1
            )')
            ->leftJoin('currencies', 'currencies.code', '=', 'orders.currency_code')
            ->select([
                'orders.user_id',
                'orders.number',
                'orders.erp_number',
                DB::raw("{$businessDate} as business_date"),
                DB::raw('orders.total_amount * COALESCE(currencies.exchange_rate, 1) as amount_rub'),
            ])
            ->get();

        $byClient = [];

        foreach ($rows as $row) {
            $at = $row->business_date === null ? null : Carbon::parse($row->business_date);

            $byClient[(int) $row->user_id] = [
                // Номер 1С информативнее внутреннего: по нему менеджер сличает
                // списки сайта и учётной системы.
                'number' => $row->erp_number ?: $row->number,
                'at' => $at?->toDateString(),
                'at_label' => $at?->format('d.m.Y'),
                'amount_rub' => round((float) $row->amount_rub, 2),
            ];
        }

        return $byClient;
    }
}

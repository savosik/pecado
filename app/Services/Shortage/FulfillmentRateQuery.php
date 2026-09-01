<?php

namespace App\Services\Shortage;

use App\Enums\Crm\CrmScope;
use App\Enums\OrderStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Степень удовлетворения заказов: какая доля заказанного действительно уехала.
 *
 * Журнал отвечает на вопрос «что отменилось», но не на вопрос «насколько это
 * много». Одна и та же сотня отмен — это провал при трёх сотнях строк за месяц
 * и погрешность при тридцати тысячах. Поэтому процент считается не по журналу,
 * а по заказам периода: знаменатель — всё заказанное, числитель — уехавшее.
 *
 * База по умолчанию — отгруженные заказы: пока заказ собирают, состав ещё
 * изменится (склад снимает позиции именно при сборке), и процент по нему был бы
 * завышен. Переключатель «все заказы» оставлен намеренно — он показывает текущую
 * картину месяца, пока заказы ещё в работе.
 *
 * Дата здесь — дата документа заказа, а не дата отмены: недобор относится
 * к тому заказу, в котором случился, даже если разобрали его через месяц.
 */
class FulfillmentRateQuery
{
    /**
     * Заказы, состав которых уже не изменится.
     *
     * Формально финальный статус — `closed`, но 1С им почти не пользуется:
     * на 7,5 тысячи заказов боевой базы закрытых три штуки, а фактическим
     * концом обработки служит «Готов к закрытию». Считать удовлетворение
     * по одному `closed` значило бы показывать пустой экран.
     *
     * Граница проходит по отгрузке: после неё склад позиции уже не снимает,
     * а ожидание оплаты к составу заказа отношения не имеет.
     *
     * @var list<OrderStatus>
     */
    private const SETTLED_STATUSES = [
        OrderStatus::AWAITING_PAYMENT,
        OrderStatus::READY_FOR_CLOSURE,
        OrderStatus::CLOSED,
    ];

    public function __construct(
        private readonly ShortageLogQuery $log,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int|string|null>
     */
    public function forFilters(array $filters, User $actor, bool $seesDepartment): array
    {
        $scope = CrmScope::resolve($filters['scope'], $actor);

        $managerId = $this->log->scopedManagerId(
            $actor,
            $seesDepartment,
            $scope,
            $seesDepartment && $filters['manager_id'] !== null ? $filters['manager_id'] : null,
        );

        if ($managerId === false) {
            return $this->empty($filters);
        }

        $perOrder = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereNull('orders.deleted_at')
            ->whereBetween(
                DB::raw('COALESCE(orders.erp_created_at, orders.created_at)'),
                [
                    Carbon::parse($filters['from'])->startOfDay(),
                    Carbon::parse($filters['to'])->endOfDay(),
                ]
            )
            ->groupBy('orders.id')
            ->select('orders.id')
            ->selectRaw('COUNT(*) as lines_total')
            ->selectRaw('SUM(CASE WHEN order_items.cancelled = 1 THEN 1 ELSE 0 END) as lines_cancelled')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as amount_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.cancelled = 1 THEN order_items.subtotal ELSE 0 END), 0) as amount_cancelled');

        if ($filters['fulfillment'] !== 'all') {
            $perOrder->whereIn(
                'orders.status',
                array_map(fn (OrderStatus $status) => $status->value, self::SETTLED_STATUSES),
            );
        }

        if ($managerId !== null) {
            $perOrder->join('users', 'users.id', '=', 'orders.user_id')
                ->where('users.personal_manager_id', $managerId);
        }

        if ($filters['user_id'] !== null) {
            $perOrder->where('orders.user_id', $filters['user_id']);
        }

        if ($filters['company_id'] !== null) {
            $perOrder->where('orders.company_id', $filters['company_id']);
        }

        $row = DB::query()
            ->fromSub($perOrder, 'per_order')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(lines_total), 0) as lines_total')
            ->selectRaw('COALESCE(SUM(lines_cancelled), 0) as lines_cancelled')
            ->selectRaw('COALESCE(SUM(amount_total), 0) as amount_total')
            ->selectRaw('COALESCE(SUM(amount_cancelled), 0) as amount_cancelled')
            ->selectRaw('SUM(CASE WHEN lines_cancelled = 0 THEN 1 ELSE 0 END) as complete_orders')
            ->first();

        $ordersCount = (int) ($row->orders_count ?? 0);
        $linesTotal = (int) ($row->lines_total ?? 0);
        $linesCancelled = (int) ($row->lines_cancelled ?? 0);
        $amountTotal = (float) ($row->amount_total ?? 0);
        $amountCancelled = (float) ($row->amount_cancelled ?? 0);
        $completeOrders = (int) ($row->complete_orders ?? 0);

        return [
            'basis' => $filters['fulfillment'],
            'orders_count' => $ordersCount,
            'complete_orders' => $completeOrders,
            'lines_total' => $linesTotal,
            'lines_cancelled' => $linesCancelled,
            'amount_total' => $amountTotal,
            'amount_cancelled' => $amountCancelled,
            // Доля уехавшего в деньгах — главная цифра: она отвечает на вопрос
            // «сколько выручки заказа мы не собрали».
            'amount_rate' => $this->rate($amountTotal - $amountCancelled, $amountTotal),
            'lines_rate' => $this->rate($linesTotal - $linesCancelled, $linesTotal),
            // Доля заказов, уехавших целиком: клиент замечает именно её —
            // ему важен не процент строк, а факт «в моём заказе чего-то нет».
            'orders_rate' => $this->rate($completeOrders, $ordersCount),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int|string|null>
     */
    private function empty(array $filters): array
    {
        return [
            'basis' => $filters['fulfillment'],
            'orders_count' => 0,
            'complete_orders' => 0,
            'lines_total' => 0,
            'lines_cancelled' => 0,
            'amount_total' => 0.0,
            'amount_cancelled' => 0.0,
            'amount_rate' => null,
            'lines_rate' => null,
            'orders_rate' => null,
        ];
    }

    /**
     * Процент с одним знаком; без базы — NULL, а не «100 %»: пустой период
     * должен читаться как «нет данных», а не как идеальный месяц.
     */
    private function rate(float|int $part, float|int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, 1);
    }
}

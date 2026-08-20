<?php

namespace App\Services\Shortage;

use App\Models\GoodsIssue;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Косвенный признак: кто, скорее всего, снял позицию из заказа.
 *
 * Причины отмены 1С не передаёт — `order.updated` одинаков и когда позицию
 * снял склад при закрытии расходного ордера, и когда клиент попросил менеджера
 * убрать её из заказа. Зацепка одна: расходный ордер. Если по заказу он уже
 * заведён, значит сборка шла, и отмена почти наверняка складская.
 *
 * Сервис ничего не записывает: метку в журнале ставит менеджер, здесь только
 * подсказка. Три градации честнее двух — «ордера нет» это не «отменил клиент»,
 * а «складского следа не нашли».
 */
class CancellationHintResolver
{
    /** Товар отменённой строки есть в расходном ордере по этому заказу. */
    public const HINT_WAREHOUSE_STRONG = 'warehouse_strong';

    /** Расходный ордер по заказу есть, но отменённого товара в нём нет. */
    public const HINT_WAREHOUSE = 'warehouse';

    /** Расходного ордера по заказу нет вовсе — сборка не начиналась. */
    public const HINT_NONE = 'none';

    public const LABELS = [
        self::HINT_WAREHOUSE_STRONG => 'Похоже на склад',
        self::HINT_WAREHOUSE => 'Возможно, склад',
        self::HINT_NONE => 'Складского следа нет',
    ];

    public const DESCRIPTIONS = [
        self::HINT_WAREHOUSE_STRONG => 'Товар из отменённой строки есть в расходном ордере по этому заказу — строку дробили при сборке.',
        self::HINT_WAREHOUSE => 'По заказу заведён расходный ордер, значит сборка шла. Отменённого товара в ордере нет.',
        self::HINT_NONE => 'Расходного ордера по заказу нет: сборка не начиналась, отмена скорее по просьбе клиента.',
    ];

    /**
     * Подсказки для набора отменённых строк — одним запросом на страницу журнала.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, array{kind: string, label: string, description: string, issues: list<array{number: string, date: string|null, status_label: string, status_color: string}>}>
     */
    public function forItems(Collection $items): array
    {
        $orderIds = $items->pluck('order_id')->filter()->unique()->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        $issueRows = DB::table('goods_issue_items as gii')
            ->join('goods_issues as g', 'g.id', '=', 'gii.goods_issue_id')
            ->whereNull('g.deleted_at')
            ->whereIn('gii.order_id', $orderIds)
            ->select([
                'gii.order_id',
                'gii.product_id',
                'g.id as issue_id',
                'g.number',
                'g.date',
                'g.status',
            ])
            ->get();

        /** @var array<int, Collection<int, object>> $byOrder */
        $byOrder = $issueRows->groupBy('order_id')->all();

        $hints = [];

        foreach ($items as $item) {
            $rows = $byOrder[$item->order_id] ?? collect();

            if ($rows->isEmpty()) {
                $hints[$item->id] = $this->hint(self::HINT_NONE, collect());

                continue;
            }

            $sameProduct = $item->product_id !== null
                && $rows->contains(fn ($row) => (int) $row->product_id === (int) $item->product_id);

            $hints[$item->id] = $this->hint(
                $sameProduct ? self::HINT_WAREHOUSE_STRONG : self::HINT_WAREHOUSE,
                $rows,
            );
        }

        return $hints;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{kind: string, label: string, description: string, issues: list<array{number: string, date: string|null, status_label: string, status_color: string}>}
     */
    private function hint(string $kind, Collection $rows): array
    {
        $issues = $rows
            ->unique('issue_id')
            ->map(fn ($row) => [
                'number' => (string) $row->number,
                'date' => $row->date ? date('d.m.Y', strtotime((string) $row->date)) : null,
                'status_label' => GoodsIssue::STATUS_LABELS[$row->status] ?? (string) $row->status,
                'status_color' => GoodsIssue::STATUS_COLORS[$row->status] ?? 'gray',
            ])
            ->values()
            ->all();

        return [
            'kind' => $kind,
            'label' => self::LABELS[$kind],
            'description' => self::DESCRIPTIONS[$kind],
            'issues' => $issues,
        ];
    }
}

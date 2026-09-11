<?php

namespace App\Services\Motivation;

use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Журнал скидок — постконтроль без влияния на расчёт (карточка mot-38; форма B11).
 *
 * Решение заказчика от 08.09.2026: согласования скидок в системе нет — скидка
 * приходит из 1С уже в составе отгрузки, — поэтому п. 9.3 Положения приостановлен,
 * а руководитель получает видимость. Источник — строки отгрузок; новых сущностей нет.
 *
 * Сумма ручной скидки по строке — базовая цена × количество × процент: 1С отдаёт
 * базовую цену и итог строки, а не сумму каждой скидки отдельно.
 */
class DiscountJournalService
{
    public const PER_PAGE = 50;

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $from, CarbonInterface $to, ?int $managerId, float $minPercent, int $page): array
    {
        $start = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->endOfDay();

        $query = DB::table('shipment_items as si')
            ->join('shipments as s', 's.id', '=', 'si.shipment_id')
            ->leftJoin('users as buyer', 'buyer.id', '=', 's.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'buyer.personal_manager_id')
            ->whereNull('s.deleted_at')
            ->whereBetween('s.erp_created_at', [$start, $end])
            ->where('si.manual_discount_percent', '>', 0)
            ->where('si.manual_discount_percent', '>=', $minPercent)
            ->when($managerId !== null, fn ($q) => $q->where('buyer.personal_manager_id', $managerId));

        // Отгрузки внутренним юрлицам — не продажи; тот же фильтр, что у аналитики.
        $query->whereIn('s.id', Shipment::query()->withoutInternalOrganizations()->select('shipments.id'));

        $items = $query->get([
            's.id as shipment_id', 's.erp_number', 's.erp_created_at', 's.user_id as partner_id',
            'buyer.name as partner_name', 'buyer.erp_name as partner_erp_name', 'pm.name as manager_name', 'pm.id as manager_id',
            'si.id as item_id', 'si.product_name_snapshot', 'si.product_id', 'si.quantity', 'si.price', 'si.subtotal', 'si.total', 'si.manual_discount_percent', 'si.auto_discount_percent',
        ]);

        $productNames = DB::table('products')->whereIn('id', $items->pluck('product_id')->filter()->unique()->all())->pluck('name', 'id')->all();

        $documents = [];
        $partners = [];
        $maxPercent = 0.0;
        $discountTotal = 0.0;

        foreach ($items as $item) {
            $pct = (float) $item->manual_discount_percent;
            $lineDiscount = Money::round((float) $item->price * (float) $item->quantity * $pct / 100);
            $maxPercent = max($maxPercent, $pct);
            $discountTotal += $lineDiscount;
            $partners[(int) $item->partner_id] = true;

            $documents[(int) $item->shipment_id] ??= [
                'shipment_id' => (int) $item->shipment_id,
                'number' => (string) $item->erp_number,
                'date' => CarbonImmutable::parse((string) $item->erp_created_at)->toDateString(),
                'partner_id' => (int) $item->partner_id,
                'partner_name' => (string) ($item->partner_erp_name ?: $item->partner_name ?: ('#'.$item->partner_id)),
                'manager_id' => $item->manager_id === null ? null : (int) $item->manager_id,
                'manager_name' => (string) ($item->manager_name ?? ''),
                'max_percent' => 0.0,
                'discount' => 0.0,
                'total' => 0.0,
                'items' => [],
            ];

            $doc = &$documents[(int) $item->shipment_id];
            $doc['max_percent'] = max($doc['max_percent'], $pct);
            $doc['discount'] += $lineDiscount;
            $doc['total'] += (float) $item->total;
            $doc['items'][] = [
                'id' => (int) $item->item_id,
                'name' => (string) ($item->product_name_snapshot ?: ($productNames[(int) $item->product_id] ?? ('#'.$item->product_id))),
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'percent' => $pct,
                'auto_percent' => (float) $item->auto_discount_percent,
                'discount' => $lineDiscount,
                'total' => (float) $item->total,
            ];
            unset($doc);
        }

        foreach ($documents as &$doc) {
            $doc['discount'] = Money::round($doc['discount']);
            $doc['total'] = Money::round($doc['total']);
            usort($doc['items'], fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);
        }
        unset($doc);

        $rows = array_values($documents);
        usort($rows, fn (array $a, array $b): int => [$b['max_percent'], $b['discount']] <=> [$a['max_percent'], $a['discount']]);

        $lastPage = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'managers' => PersonalManager::query()->active()->where('payroll_enabled', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (PersonalManager $m): array => ['id' => (int) $m->getKey(), 'name' => (string) $m->name])->all(),
            'summary' => [
                'documents' => count($rows),
                'lines' => $items->count(),
                'partners' => count($partners),
                'max_percent' => $maxPercent,
                'discount' => Money::round($discountTotal),
            ],
            'rows' => [
                'data' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => count($rows),
                'last_page' => $lastPage,
            ],
            'note' => 'Скидка приходит из 1С уже в составе отгрузки: согласования до отгрузки в системе нет, поэтому журнал не влияет на показатели и отгрузки не блокирует (п. 9.3 Положения приостановлен решением от 08.09.2026).',
        ];
    }
}

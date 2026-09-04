<?php

namespace App\Services\Product;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Балл сортировки каталога «По умолчанию» (products.sort_score).
 *
 * Два признака продаж за скользящее окно, оба по реализациям из 1С:
 *  - выручка (`shipment_items.total`, конвертированная в рубли);
 *  - охват — сколько разных контрагентов купили товар за окно.
 *
 * Каждый признак — координата вектора товара. Сырые значения тяжелохвостые
 * (топовая позиция даёт выручку на два порядка выше медианной), поэтому
 * координата сжимается логарифмом и делится на максимум по каталогу:
 * ln(1 + x) / ln(1 + max) ∈ [0, 1]. Дальше — скалярное произведение с
 * вектором экспертных весов, приведённым к сумме 1 (единичный по L1):
 * итог тоже лежит в [0, 1] и масштабируется в 0…scale.
 *
 * Наличие в балл НЕ входит намеренно. «Товары не в наличии всегда ниже»
 * обеспечивает полка в ORDER BY (App\Enums\CatalogSort) по живым остаткам:
 * зашей мы наличие в балл — товар, приехавший на склад после ночного
 * пересчёта, до следующей ночи висел бы под теми, кого уже нет.
 */
class CatalogSortScoreCalculator
{
    /**
     * Посчитать баллы всех товаров, у которых были продажи за окно.
     *
     * Товары без продаж в результат не попадают — им ставится ноль
     * (см. persist()). Ключ результата — products.id, порядок — по убыванию балла.
     *
     * @return array<int, array{revenue: float, reach: int, revenue_norm: float, reach_norm: float, score: float}>
     */
    public function calculate(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $from = $now->copy()->subDays($this->windowDays());

        $rows = $this->salesWindow($from, $now);

        if ($rows === []) {
            return [];
        }

        $maxRevenue = max(array_column($rows, 'revenue'));
        $maxReach = max(array_column($rows, 'reach'));

        $weights = $this->weights();
        $scale = (float) config('catalog_ranking.scale', 1000);

        $scored = [];

        foreach ($rows as $productId => $row) {
            $revenueNorm = $this->normalize($row['revenue'], $maxRevenue);
            $reachNorm = $this->normalize($row['reach'], $maxReach);

            $merit = $revenueNorm * $weights['revenue'] + $reachNorm * $weights['reach'];

            $scored[$productId] = [
                'revenue' => $row['revenue'],
                'reach' => $row['reach'],
                'revenue_norm' => round($revenueNorm, 6),
                'reach_norm' => round($reachNorm, 6),
                'score' => round($merit * $scale, 4),
            ];
        }

        uasort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * Записать баллы в products.
     *
     * Пишем через query builder, а не через модели: 9 тысяч save() подняли бы
     * наблюдателей товара (переиндексация Scout, сброс кешей главной) на ровном
     * месте — балл сортировки к содержимому карточки отношения не имеет.
     * `products.updated_at` тоже намеренно не трогаем.
     *
     * @param  array<int, array{score: float, ...}>  $scored
     * @return array{scored: int, zeroed: int}
     */
    public function persist(array $scored, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $stamp = $now->format('Y-m-d H:i:s');

        return DB::transaction(function () use ($scored, $stamp) {
            // Сначала обнуляем весь каталог: товар, выпавший из окна продаж,
            // обязан потерять вчерашний балл, а не унести его в сегодня.
            $zeroed = DB::table('products')
                ->where('sort_score', '<>', 0)
                ->update(['sort_score' => 0, 'sort_score_updated_at' => $stamp]);

            $chunk = max(50, (int) config('catalog_ranking.chunk', 500));

            foreach (array_chunk($scored, $chunk, true) as $batch) {
                $ids = [];
                $cases = '';

                // Числа собираются в текст запроса, а не в биндинги: и id, и балл
                // мы посчитали сами и приводим к int/float — подставлять здесь
                // нечего, зато один UPDATE вместо пачки построчных.
                foreach ($batch as $productId => $row) {
                    $id = (int) $productId;
                    $ids[] = $id;
                    $cases .= sprintf(' WHEN %d THEN %s', $id, number_format((float) $row['score'], 4, '.', ''));
                }

                DB::table('products')
                    ->whereIn('id', $ids)
                    ->update([
                        'sort_score' => DB::raw('CASE id'.$cases.' ELSE sort_score END'),
                        'sort_score_updated_at' => $stamp,
                    ]);
            }

            return ['scored' => count($scored), 'zeroed' => $zeroed];
        });
    }

    /**
     * Границы окна расчёта — для вывода в команде и в тестах.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public function window(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return ['from' => $now->copy()->subDays($this->windowDays()), 'to' => $now->copy()];
    }

    public function windowDays(): int
    {
        return max(1, (int) config('catalog_ranking.window_days', 90));
    }

    /**
     * Экспертные веса, приведённые к сумме 1.
     *
     * @return array{revenue: float, reach: float}
     */
    public function weights(): array
    {
        $revenue = max(0.0, (float) config('catalog_ranking.weights.revenue', 0.5));
        $reach = max(0.0, (float) config('catalog_ranking.weights.reach', 0.5));

        $sum = $revenue + $reach;

        // Оба веса обнулены — считаем это опечаткой в конфиге, а не запретом
        // на сортировку: возвращаемся к равному вкладу.
        if ($sum <= 0.0) {
            return ['revenue' => 0.5, 'reach' => 0.5];
        }

        return ['revenue' => $revenue / $sum, 'reach' => $reach / $sum];
    }

    /**
     * Логарифмическая нормировка признака в [0, 1].
     *
     * ln(1 + x) / ln(1 + max): линейное деление на максимум прижало бы к нулю
     * весь каталог, кроме первой десятки позиций.
     */
    private function normalize(float $value, float $max): float
    {
        if ($max <= 0.0 || $value <= 0.0) {
            return 0.0;
        }

        return min(1.0, log(1 + $value) / log(1 + $max));
    }

    /**
     * Выручка и охват по каждому товару за окно.
     *
     * Ось дат — бизнес-дата документа 1С (`erp_created_at`), как во всей
     * аналитике; у документов доисторической загрузки её нет, поэтому падаем
     * на `created_at`. Контрагент — юрлицо (`company_id`), а без него ИНН:
     * тот же ключ, что в разрезах ShipmentAnalyticsService.
     *
     * @return array<int, array{revenue: float, reach: int}>
     */
    private function salesWindow(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->leftJoin('currencies', 'currencies.code', '=', 'shipments.currency_code')
            ->whereNull('shipments.deleted_at')
            ->whereNotNull('shipment_items.product_id')
            ->whereRaw('COALESCE(shipments.erp_created_at, shipments.created_at) >= ?', [$from->format('Y-m-d H:i:s')])
            ->whereRaw('COALESCE(shipments.erp_created_at, shipments.created_at) <= ?', [$to->format('Y-m-d H:i:s')])
            ->groupBy('shipment_items.product_id')
            ->selectRaw('
                shipment_items.product_id AS product_id,
                COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS revenue,
                COUNT(DISTINCT COALESCE(CAST(shipments.company_id AS CHAR), shipments.tax_id)) AS reach
            ')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            // Возвраты уменьшают выручку до отрицательной — для сортировки это ноль.
            $result[(int) $row->product_id] = [
                'revenue' => max(0.0, (float) $row->revenue),
                'reach' => (int) $row->reach,
            ];
        }

        return $result;
    }
}

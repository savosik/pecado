<?php

namespace App\Services\Defect;

use App\Models\ClientStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Справочная цена товара «по статусу клиента» — подсказка закупщику при уценке.
 *
 * Индивидуальные цены 1С выгружает по партнёру, а не по статусу лояльности,
 * поэтому цену статуса восстанавливаем статистически: берём цены всех клиентов
 * этого статуса по товару и выбираем самую частую (моду). Мода устойчивее
 * среднего и минимума — единичный клиент с личным договором не сдвигает
 * ориентир для всего статуса.
 *
 * Показываем статус с самой низкой ценой на товар — это и есть старший
 * статус (Diamond); если по нему цены нет, следующей окажется цена VIP, за
 * ней Gold и так далее. Порядок берём из самих цен, а не из названий или
 * amount_from: на проде amount_from у статусов не заполнен, и сортировка по
 * нему схлопывалась в алфавитную — наверх всплывал Bronze. Какой статус в
 * итоге сработал — всегда видно в UI.
 */
class DefectReferencePriceService
{
    /** Цены из 1С меняются пачками раз в сутки — короткого кеша достаточно. */
    private const CACHE_TTL = 300;

    /**
     * Справочные цены по товарам.
     *
     * @param  array<int, int|string>  $productIds
     * @return array<int, array{price: float|null, status: array{id: int, name: string, color: string|null}|null, clients: int, ladder: list<array{status_id: int, name: string, color: string|null, price: float, clients: int}>}>
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $ladder = $this->statusLadder();

        if ($ladder === []) {
            return [];
        }

        $prices = Cache::remember(
            'defect-ref-prices:'.md5(implode(',', $productIds)),
            self::CACHE_TTL,
            fn () => $this->loadPrices($productIds, $ladder)
        );

        $result = [];

        foreach ($productIds as $productId) {
            $rows = [];

            foreach ($ladder as $tier => $status) {
                $found = $prices[$status['id']][$productId] ?? null;

                if ($found === null) {
                    continue;
                }

                $rows[] = [
                    'status_id' => $status['id'],
                    'name' => $status['name'],
                    'color' => $status['color'],
                    'price' => $found['price'],
                    'clients' => $found['clients'],
                    'tier' => $tier,
                ];
            }

            // Первым — самый выгодный для клиента статус. При равных ценах
            // выигрывает старший статус (порядок из statusLadder()).
            usort($rows, fn (array $a, array $b) => [$a['price'], $a['tier']] <=> [$b['price'], $b['tier']]);

            $rows = array_map(function (array $row) {
                unset($row['tier']);

                return $row;
            }, $rows);

            $best = $rows[0] ?? null;

            $result[$productId] = [
                'price' => $best['price'] ?? null,
                'status' => $best === null ? null : [
                    'id' => $best['status_id'],
                    'name' => $best['name'],
                    'color' => $best['color'],
                ],
                'clients' => $best['clients'] ?? 0,
                'ladder' => $rows,
            ];
        }

        return $result;
    }

    /**
     * Справочная цена одного товара (для точечных проверок и массовой установки).
     */
    public function priceFor(int $productId): ?float
    {
        return $this->forProducts([$productId])[$productId]['price'] ?? null;
    }

    /**
     * Статусы лояльности, у которых есть клиенты из 1С.
     *
     * Порядок здесь — только тай-брейк для статусов с одинаковой ценой:
     * сперва тот, у кого выше порог amount_from. Основной выбор делает цена.
     *
     * @return list<array{id: int, name: string, color: string|null, partners: list<int>}>
     */
    private function statusLadder(): array
    {
        return Cache::remember('defect-ref-prices:status-ladder', self::CACHE_TTL, function () {
            $statuses = ClientStatus::query()
                ->orderByRaw('amount_from IS NULL')
                ->orderByDesc('amount_from')
                ->orderBy('name')
                ->get(['id', 'name', 'color']);

            if ($statuses->isEmpty()) {
                return [];
            }

            // Только выгруженные из 1С партнёры — у остальных индивидуальных цен нет.
            $partners = User::query()
                ->whereNotNull('client_status_id')
                ->whereNotNull('erp_id')
                ->get(['id', 'client_status_id'])
                ->groupBy('client_status_id');

            $ladder = [];

            foreach ($statuses as $status) {
                $ids = $partners->get($status->id, collect())->pluck('id')->all();

                if ($ids === []) {
                    continue;
                }

                $ladder[] = [
                    'id' => (int) $status->id,
                    'name' => $status->name,
                    'color' => $status->color,
                    'partners' => array_map('intval', $ids),
                ];
            }

            return $ladder;
        });
    }

    /**
     * Мода цены по каждому статусу и товару.
     *
     * Агрегируем на стороне prices DB: наружу выходит по несколько строк на
     * статус, а не десятки тысяч персональных цен. При недоступности базы
     * цен деградируем молча — страница уценки должна открываться всегда.
     *
     * @param  list<int>  $productIds
     * @param  list<array{id: int, name: string, color: string|null, partners: list<int>}>  $ladder
     * @return array<int, array<int, array{price: float, clients: int}>>
     */
    private function loadPrices(array $productIds, array $ladder): array
    {
        $result = [];

        foreach ($ladder as $status) {
            try {
                $rows = DB::connection('prices')
                    ->table('individual_prices')
                    ->whereIn('product_id', $productIds)
                    ->whereIn('partner_id', $status['partners'])
                    ->where('price', '>', 0)
                    ->selectRaw('product_id, price, COUNT(DISTINCT partner_id) as clients')
                    ->groupBy('product_id', 'price')
                    ->get();
            } catch (QueryException $e) {
                Log::warning('DefectReferencePriceService: prices DB недоступна', [
                    'error' => $e->getMessage(),
                ]);

                return $result;
            }

            $byProduct = [];

            foreach ($rows as $row) {
                $productId = (int) $row->product_id;
                $price = (float) $row->price;
                $clients = (int) $row->clients;

                $current = $byProduct[$productId] ?? null;

                // Побеждает самая распространённая цена; при равенстве — меньшая.
                if ($current === null
                    || $clients > $current['clients']
                    || ($clients === $current['clients'] && $price < $current['price'])
                ) {
                    $byProduct[$productId] = ['price' => $price, 'clients' => $clients];
                }
            }

            $result[$status['id']] = $byProduct;
        }

        return $result;
    }
}

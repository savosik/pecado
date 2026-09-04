<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Product\CatalogSortScoreCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ночной пересчёт балла сортировки каталога «По умолчанию».
 *
 * Балл (`products.sort_score`, 0–1000) тем выше, чем больше товар принёс
 * выручки и чем шире его охват по контрагентам за скользящее окно — оба
 * признака нормируются и складываются с экспертными весами из
 * `config/catalog_ranking.php`.
 *
 * Наличие в балл не входит: полку «в наличии → предзаказ → остальное»
 * каталог ставит в ORDER BY по живым остаткам (App\Enums\CatalogSort).
 */
class RebuildCatalogSortScores extends Command
{
    protected $signature = 'catalog:rebuild-sort-scores
                            {--dry-run : Посчитать и показать, ничего не записывая}
                            {--top=0 : Показать N товаров с наибольшим баллом}';

    protected $description = 'Пересчитать балл сортировки каталога «по умолчанию» (выручка + охват клиентов за окно)';

    public function handle(CatalogSortScoreCalculator $calculator): int
    {
        $window = $calculator->window();
        $weights = $calculator->weights();

        $this->info(sprintf(
            'Окно: %s — %s (%d дн.); веса: выручка %.2f, охват %.2f.',
            $window['from']->format('d.m.Y'),
            $window['to']->format('d.m.Y'),
            $calculator->windowDays(),
            $weights['revenue'],
            $weights['reach'],
        ));

        $scored = $calculator->calculate();

        if ($scored === []) {
            $this->warn('За окно нет ни одной реализации — баллы не пересчитаны.');

            return self::SUCCESS;
        }

        $top = (int) $this->option('top');
        if ($top > 0) {
            $this->renderTop($scored, $top);
        }

        if ($this->option('dry-run')) {
            $this->comment(sprintf('Сухой прогон: балл получили бы %d товаров, запись не выполнена.', count($scored)));

            return self::SUCCESS;
        }

        $result = $calculator->persist($scored);

        $this->info(sprintf(
            'Баллы записаны: %d товаров с продажами, обнулено %d.',
            $result['scored'],
            $result['zeroed'],
        ));

        Log::info('catalog-sort-score: пересчёт завершён', [
            'window_days' => $calculator->windowDays(),
            'weights' => $weights,
            'scored' => $result['scored'],
            'zeroed' => $result['zeroed'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Верхушка выдачи с раскладкой признаков — чтобы порядок можно было объяснить.
     *
     * @param  array<int, array{revenue: float, reach: int, revenue_norm: float, reach_norm: float, score: float}>  $scored
     */
    private function renderTop(array $scored, int $limit): void
    {
        $slice = array_slice($scored, 0, $limit, true);

        $names = Product::query()
            ->whereIn('id', array_keys($slice))
            ->pluck('name', 'id');

        $this->table(
            ['#', 'Товар', 'Балл', 'Выручка, ₽', 'Клиентов', 'Норм. выручка', 'Норм. охват'],
            collect($slice)->map(fn (array $row, int $id) => [
                $id,
                mb_strimwidth((string) ($names[$id] ?? '—'), 0, 44, '…'),
                number_format($row['score'], 2, ',', ' '),
                number_format($row['revenue'], 0, ',', ' '),
                $row['reach'],
                number_format($row['revenue_norm'], 3, ',', ' '),
                number_format($row['reach_norm'], 3, ',', ' '),
            ])->values()->all(),
        );
    }
}

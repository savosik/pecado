<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Пересчитывает поле `sort` у категорий по числу товаров в наличии в указанном регионе
 * (по умолчанию — Москва). Учитываются товары на складах с типом `primary` и `preorder`.
 * Подсчёт идёт на всю глубину подкатегорий, а сортировка раздаётся среди соседей: каждой
 * группе детей с общим parent_id выставляется 1..N — меньше значение, больше товаров.
 */
class ResortCategoriesByMoscowStock extends Command
{
    protected $signature = 'categories:resort-by-moscow-stock
                            {--region=Москва : Название региона (точное совпадение)}
                            {--dry-run : Не записывать sort, только показать план}';

    protected $description = 'Перевыставить categories.sort по числу товаров в наличии в регионе (default: Москва), с учётом подкатегорий.';

    public function handle(): int
    {
        $regionName = (string) $this->option('region');
        $dryRun = (bool) $this->option('dry-run');

        $region = Region::where('name', $regionName)->first();
        if (! $region) {
            $this->error("Регион «{$regionName}» не найден.");

            return self::FAILURE;
        }

        $this->info("Регион: {$region->name} (id={$region->id})");

        $warehouseIds = DB::table('region_warehouse')
            ->where('region_id', $region->id)
            ->whereIn('type', ['primary', 'preorder'])
            ->pluck('warehouse_id')
            ->all();

        if (empty($warehouseIds)) {
            $this->warn('У региона нет складов (primary/preorder) — все категории получат sort = NULL.');
            $directCounts = [];
        } else {
            $directCounts = $this->collectDirectCounts($warehouseIds);
        }

        $this->info('Прямых соответствий «категория → товары в наличии»: '.count($directCounts));

        /** @var array<int, Category> $categories */
        $categories = Category::query()
            ->get(['id', 'parent_id', 'name', '_lft', '_rgt'])
            ->keyBy('id')
            ->all();

        $deepCounts = $this->computeDeepCounts($categories, $directCounts);
        $plan = $this->buildSortPlan($categories, $deepCounts);

        if ($dryRun) {
            $this->table(
                ['parent_id', 'category_id', 'name', 'stock_count', 'sort'],
                collect($plan)
                    ->map(fn (array $row) => [
                        $row['parent_id'] ?? 'root',
                        $row['id'],
                        $row['name'],
                        $row['count'],
                        $row['sort'] ?? 'NULL',
                    ])
                    ->all()
            );
            $hidden = collect($plan)->whereStrict('sort', null)->count();
            $this->info("[DRY RUN] изменения не записаны. Без товаров (sort = NULL): {$hidden}.");

            return self::SUCCESS;
        }

        $this->writeSort($plan);
        $this->info('Готово: обновлено '.count($plan).' категорий.');

        return self::SUCCESS;
    }

    /**
     * Карта category_id → число товаров с суммарным остатком > 0 на указанных складах.
     *
     * @param  array<int, int>  $warehouseIds
     * @return array<int, int>
     */
    private function collectDirectCounts(array $warehouseIds): array
    {
        $rows = DB::table('products')
            ->join('product_warehouse', 'product_warehouse.product_id', '=', 'products.id')
            ->whereIn('product_warehouse.warehouse_id', $warehouseIds)
            ->whereNotNull('products.category_id')
            ->where('products.hidden', false)
            ->select(
                'products.category_id',
                'products.id as product_id',
                DB::raw('SUM(product_warehouse.quantity) as qty')
            )
            ->groupBy('products.category_id', 'products.id')
            ->having('qty', '>', 0)
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $cid = (int) $row->category_id;
            $counts[$cid] = ($counts[$cid] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Сумма прямых counts по всей подветке (включая саму категорию).
     *
     * @param  array<int, Category>  $categories
     * @param  array<int, int>  $directCounts
     * @return array<int, int>
     */
    private function computeDeepCounts(array $categories, array $directCounts): array
    {
        $childrenByParent = [];
        foreach ($categories as $cat) {
            $childrenByParent[(int) ($cat->parent_id ?? 0)][] = (int) $cat->id;
        }

        $deep = [];
        $visit = function (int $id) use (&$visit, &$deep, $directCounts, $childrenByParent): int {
            if (isset($deep[$id])) {
                return $deep[$id];
            }
            $sum = $directCounts[$id] ?? 0;
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $sum += $visit($childId);
            }

            return $deep[$id] = $sum;
        };

        foreach ($categories as $cat) {
            $visit((int) $cat->id);
        }

        return $deep;
    }

    /**
     * План записи: список вида
     * [['id' => ..., 'parent_id' => ..., 'name' => ..., 'count' => ..., 'sort' => ...], ...].
     *
     * Среди соседей с общим parent_id раздаём 1..N по убыванию count;
     * при равенстве — по имени, чтобы порядок был детерминирован.
     * Категориям с deep_count = 0 ставим sort = NULL — они скрыты в каталог-панели.
     *
     * @param  array<int, Category>  $categories
     * @param  array<int, int>  $deepCounts
     * @return list<array{id:int,parent_id:?int,name:string,count:int,sort:?int}>
     */
    private function buildSortPlan(array $categories, array $deepCounts): array
    {
        $bySiblings = [];
        foreach ($categories as $cat) {
            $bySiblings[(int) ($cat->parent_id ?? 0)][] = $cat;
        }

        $plan = [];
        foreach ($bySiblings as $siblings) {
            usort($siblings, function (Category $a, Category $b) use ($deepCounts) {
                $ca = $deepCounts[(int) $a->id] ?? 0;
                $cb = $deepCounts[(int) $b->id] ?? 0;
                if ($ca !== $cb) {
                    return $cb <=> $ca; // больше товаров — выше (меньший sort)
                }

                return strnatcasecmp((string) $a->name, (string) $b->name);
            });

            $position = 1;
            foreach ($siblings as $cat) {
                $count = $deepCounts[(int) $cat->id] ?? 0;
                $plan[] = [
                    'id' => (int) $cat->id,
                    'parent_id' => $cat->parent_id !== null ? (int) $cat->parent_id : null,
                    'name' => (string) $cat->name,
                    'count' => $count,
                    'sort' => $count > 0 ? $position++ : null,
                ];
            }
        }

        return $plan;
    }

    /**
     * @param  list<array{id:int,sort:?int}>  $plan
     */
    private function writeSort(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            foreach (array_chunk($plan, 200) as $chunk) {
                $ids = array_column($chunk, 'id');
                $cases = '';
                foreach ($chunk as $row) {
                    $value = $row['sort'] === null ? 'NULL' : (string) (int) $row['sort'];
                    $cases .= ' WHEN '.(int) $row['id'].' THEN '.$value;
                }
                DB::statement(
                    'UPDATE categories SET sort = CASE id'.$cases.' END WHERE id IN ('.implode(',', $ids).')'
                );
            }
        });
    }
}

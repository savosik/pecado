<?php

namespace App\Console\Commands;

use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Substitution\SubstitutionCandidateService;
use Illuminate\Console\Command;

/**
 * Отладка автоподбора: показать слои и кандидатов до включения контура.
 *
 * Три режима:
 *  - по строке заказа: substitutions:preview 123
 *  - по товару (синтетическая строка): substitutions:preview --product=456
 *  - прогон по всем историческим отменам: substitutions:preview --historical
 */
class SubstitutionsPreview extends Command
{
    protected $signature = 'substitutions:preview
        {order_item? : ID отменённой строки заказа (order_items.id)}
        {--product= : ID товара — подбор по синтетической строке (5 шт. по базовой цене)}
        {--historical : Прогон по всем отменённым строкам за историю с агрегатами}';

    protected $description = 'Показать кандидатов автоподбора замен по строке заказа, товару или всей истории отмен';

    public function handle(SubstitutionCandidateService $engine): int
    {
        if ($this->option('historical')) {
            return $this->historical($engine);
        }

        $item = $this->resolveItem();

        if ($item === null) {
            $this->error('Укажите order_item, --product или --historical.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            '<info>Строка:</info> %s — %d шт., цена %.2f',
            $item->name,
            $item->quantity,
            (float) $item->final_price,
        ));

        $set = $engine->forOrderItem($item);

        $this->line('<info>Подождать прихода:</info> '.($set->waitAvailable ? ($set->waitReason ?? 'да') : 'нет'));

        if ($set->candidates === []) {
            $this->warn('Кандидатов нет — строка уйдёт менеджеру на ручной подбор.');

            return self::SUCCESS;
        }

        $this->table(
            ['Слой', 'Кандидат', 'Цена', 'Остаток', 'Кол-во', 'Причина'],
            array_map(function (array $candidate) {
                $name = $candidate['product_id']
                    ? Product::withoutGlobalScopes()->find($candidate['product_id'])?->name
                    : 'уценка #'.$candidate['product_defect_id'];

                return [
                    $candidate['kind']->value,
                    mb_strimwidth((string) $name, 0, 60, '…'),
                    number_format($candidate['price'], 2, ',', ' '),
                    $candidate['available'],
                    $candidate['suggested_quantity'],
                    mb_strimwidth($candidate['reason'], 0, 50, '…'),
                ];
            }, $set->candidates),
        );

        return self::SUCCESS;
    }

    private function resolveItem(): ?OrderItem
    {
        $itemId = $this->argument('order_item');

        if ($itemId !== null) {
            return OrderItem::query()->findOrFail((int) $itemId);
        }

        $productId = $this->option('product');

        if ($productId === null) {
            return null;
        }

        $product = Product::withoutGlobalScopes()->findOrFail((int) $productId);

        // Синтетическая строка: 5 штук по базовой цене, без заказа и клиента.
        $item = new OrderItem([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 5,
            'final_price' => $product->base_price,
            'cancelled' => true,
        ]);
        $item->setRelation('product', $product);

        return $item;
    }

    /**
     * Валидация качества: прогон по всем историческим отменённым строкам.
     */
    private function historical(SubstitutionCandidateService $engine): int
    {
        $items = OrderItem::query()
            ->where('cancelled', true)
            ->with(['product.category', 'order.user'])
            ->get();

        if ($items->isEmpty()) {
            $this->warn('Отменённых строк в базе нет.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        $covered = 0;
        $waitOnly = 0;
        $empty = 0;
        $noProduct = 0;
        $byKind = [];
        $emptyLines = [];

        foreach ($items as $item) {
            $bar->advance();

            if ($item->product_id === null) {
                $noProduct++;

                continue;
            }

            $set = $engine->forOrderItem($item);

            if ($set->candidates !== []) {
                $covered++;

                foreach ($set->candidates as $candidate) {
                    $kind = $candidate['kind']->value;
                    $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
                }
            } elseif ($set->waitAvailable) {
                $waitOnly++;
            } else {
                $empty++;
                $emptyLines[] = sprintf('#%d %s (%d шт.)', $item->id, mb_strimwidth($item->name, 0, 60, '…'), $item->quantity);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $total = $items->count();
        $withProduct = $total - $noProduct;

        $this->info('Прогон по историческим отменам');
        $this->table(['Показатель', 'Значение'], [
            ['Всего отменённых строк', $total],
            ['Без привязки к товару (рассинхрон)', $noProduct],
            ['Есть кандидаты', sprintf('%d (%.0f%% строк с товаром)', $covered, $withProduct > 0 ? $covered / $withProduct * 100 : 0)],
            ['Только «подождать прихода»', $waitOnly],
            ['Пусто (ручной подбор)', $empty],
        ]);

        if ($byKind !== []) {
            arsort($byKind);
            $this->info('Кандидаты по слоям');
            $this->table(['Слой', 'Кандидатов'], collect($byKind)->map(fn ($count, $kind) => [$kind, $count])->values()->all());
        }

        if ($emptyLines !== []) {
            $this->info('Строки без кандидатов (нужен ручной подбор):');

            foreach (array_slice($emptyLines, 0, 30) as $line) {
                $this->line('  '.$line);
            }
        }

        return self::SUCCESS;
    }
}

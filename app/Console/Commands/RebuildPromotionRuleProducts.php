<?php

namespace App\Console\Commands;

use App\Jobs\RecalculatePromotionRuleProductsJob;
use App\Models\PromotionRule;
use Illuminate\Console\Command;

/**
 * Батчевый пересчёт товаров-участников правил акций.
 *
 * Состав категории или набор тегов товара меняются массово, и дёргать пересчёт
 * на каждый товар нельзя — вместо этого раз в сутки (и вручную из админки)
 * пересобираем списки целиком.
 */
class RebuildPromotionRuleProducts extends Command
{
    protected $signature = 'promo:rebuild-rule-products
                            {--rule=* : Пересчитать только указанные правила (ID)}
                            {--queue : Отправить пересчёт в очередь вместо синхронного выполнения}';

    protected $description = 'Пересобрать списки товаров-участников правил акций (promotion_rule_product)';

    public function handle(): int
    {
        $ruleIds = array_filter(array_map('intval', (array) $this->option('rule')));

        $query = PromotionRule::query()->orderBy('id');

        if ($ruleIds !== []) {
            $query->whereIn('id', $ruleIds);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Правил для пересчёта нет.');

            return self::SUCCESS;
        }

        $useQueue = (bool) $this->option('queue');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($rules) use ($useQueue, $bar) {
            foreach ($rules as $rule) {
                $job = new RecalculatePromotionRuleProductsJob($rule->id);

                $useQueue
                    ? dispatch($job)
                    : dispatch_sync($job);

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info($useQueue
            ? "Пересчёт {$total} правил отправлен в очередь."
            : "Пересчитано правил: {$total}.");

        return self::SUCCESS;
    }
}
